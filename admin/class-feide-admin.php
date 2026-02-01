<?php
/**
 * Admin-panel for FEIDE WordPress Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class Feide_WP_Auth_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Import/Export AJAX handlers
        add_action('wp_ajax_feide_export_settings', array($this, 'ajax_export_settings'));
        add_action('wp_ajax_feide_import_settings', array($this, 'ajax_import_settings'));
        add_action('wp_ajax_feide_replace_urls', array($this, 'ajax_replace_urls'));
        add_action('wp_ajax_feide_restore_backup', array($this, 'ajax_restore_backup'));
        add_action('wp_ajax_feide_download_backup', array($this, 'ajax_download_backup'));
        add_action('wp_ajax_feide_delete_backup', array($this, 'ajax_delete_backup'));

        // Endpoint connectivity testing
        add_action('wp_ajax_feide_test_endpoint', array($this, 'ajax_test_endpoint'));
    }

    /**
     * Legg til admin-meny
     */
    public function add_admin_menu() {
        add_options_page(
            'FEIDE Autentisering',
            'FEIDE Autentisering',
            'manage_options',
            'feide-wp-auth',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Registrer innstillinger
     */
    public function register_settings() {
        register_setting('feide_wp_auth_settings_group', 'feide_wp_auth_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Sanitize innstillinger
     */
    public function sanitize_settings($input) {
        // Hent eksisterende innstillinger
        $existing = get_option('feide_wp_auth_settings', array());

        // Start med eksisterende verdier
        $sanitized = $existing;

        // OpenID Connect innstillinger - kun oppdater hvis de finnes i input
        if (isset($input['client_id'])) {
            // Kun trim whitespace - ikke modifiser selve verdien
            $sanitized['client_id'] = trim($input['client_id']);

            // Debug logging
            if (WP_DEBUG) {
                error_log('FEIDE Auth: Saving Client ID - Length: ' . strlen($sanitized['client_id']) . ', Value: ' . $sanitized['client_id']);
            }
        }
        if (isset($input['client_secret'])) {
            // Kun trim whitespace - ikke modifiser selve verdien (kritisk for autentisering)
            $sanitized['client_secret'] = trim($input['client_secret']);

            // Debug logging (vis bare lengde og første/siste tegn for sikkerhet)
            if (WP_DEBUG) {
                $secret_len = strlen($sanitized['client_secret']);
                $secret_preview = $secret_len > 0 ? substr($sanitized['client_secret'], 0, 8) . '...' . substr($sanitized['client_secret'], -4) : 'EMPTY';
                error_log('FEIDE Auth: Saving Client Secret - Length: ' . $secret_len . ', Preview: ' . $secret_preview);
            }
        }
        if (isset($input['redirect_uri'])) {
            $sanitized['redirect_uri'] = esc_url_raw($input['redirect_uri']);
        }
        if (isset($input['scope'])) {
            $sanitized['scope'] = sanitize_text_field($input['scope']);
        }
        if (isset($input['authorize_endpoint'])) {
            $sanitized['authorize_endpoint'] = esc_url_raw($input['authorize_endpoint']);
        }
        if (isset($input['token_endpoint'])) {
            $sanitized['token_endpoint'] = esc_url_raw($input['token_endpoint']);
        }
        if (isset($input['userinfo_endpoint'])) {
            $sanitized['userinfo_endpoint'] = esc_url_raw($input['userinfo_endpoint']);
        }
        if (isset($input['groupinfo_endpoint'])) {
            $sanitized['groupinfo_endpoint'] = esc_url_raw($input['groupinfo_endpoint']);
        }

        // Auto-oppretting av brukere - kun oppdater hvis checkbox finnes i skjemaet
        // Vi må sjekke om dette feltet faktisk er en del av det innsendte skjemaet
        if (array_key_exists('auto_create_users', $input)) {
            $sanitized['auto_create_users'] = isset($input['auto_create_users']) ? true : false;
        }

        // Tillat alle autentiserte brukere
        if (array_key_exists('allow_all_authenticated', $input)) {
            $sanitized['allow_all_authenticated'] = isset($input['allow_all_authenticated']) ? true : false;
        }

        // Standard rolle for nye brukere
        if (isset($input['default_role'])) {
            $sanitized['default_role'] = sanitize_text_field($input['default_role']);
        }

        // Redirect URL etter innlogging
        if (isset($input['redirect_after_login'])) {
            $sanitized['redirect_after_login'] = esc_url_raw($input['redirect_after_login']);
        }

        // Debug-logging
        if (array_key_exists('enable_debug_logging', $input)) {
            $sanitized['enable_debug_logging'] = isset($input['enable_debug_logging']) ? true : false;
        }

        // Attributt-mapping - kun oppdater hvis det finnes i input
        if (isset($input['attribute_mapping']) && is_array($input['attribute_mapping'])) {
            $sanitized['attribute_mapping'] = array();
            foreach ($input['attribute_mapping'] as $key => $value) {
                $sanitized['attribute_mapping'][sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        // Rolle-mappinger - kun oppdater hvis det finnes i input
        if (isset($input['role_mappings']) && is_array($input['role_mappings'])) {
            $sanitized['role_mappings'] = array();
            foreach ($input['role_mappings'] as $mapping) {
                if (isset($mapping['role']) && isset($mapping['criteria'])) {
                    $clean_mapping = array(
                        'role' => sanitize_text_field($mapping['role']),
                        'name' => isset($mapping['name']) ? sanitize_text_field($mapping['name']) : '',
                        'operator' => isset($mapping['operator']) ? sanitize_text_field($mapping['operator']) : 'AND',
                        'criteria' => array()
                    );

                    foreach ($mapping['criteria'] as $criterion) {
                        if (!empty($criterion['attribute']) || !empty($criterion['value'])) {
                            $clean_mapping['criteria'][] = array(
                                'attribute' => sanitize_text_field($criterion['attribute']),
                                'comparison' => sanitize_text_field($criterion['comparison']),
                                'value' => sanitize_text_field($criterion['value'])
                            );
                        }
                    }

                    $sanitized['role_mappings'][] = $clean_mapping;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Last inn admin-scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_feide-wp-auth') {
            return;
        }

        wp_enqueue_style('feide-admin-css', FEIDE_WP_AUTH_PLUGIN_URL . 'assets/css/admin.css', array(), FEIDE_WP_AUTH_VERSION);
        wp_enqueue_script('feide-admin-js', FEIDE_WP_AUTH_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), FEIDE_WP_AUTH_VERSION, true);

        wp_localize_script('feide-admin-js', 'feideAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('feide_admin_nonce'),
            'test_auth_nonce' => wp_create_nonce('feide_test_auth')
        ));
    }

    /**
     * Render admin-side
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        $settings = get_option('feide_wp_auth_settings', array());

        ?>
        <div class="wrap">
            <h1>FEIDE WordPress Autentisering</h1>

            <!-- Configuration Status Widget -->
            <div class="feide-config-status" style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <h3 style="margin-top: 0; display: flex; align-items: center;">
                    ⚙️ Konfigurasjonsstatus
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px;">
                    <?php
                    $config_checks = array(
                        'client_id' => array(
                            'label' => 'Client ID konfigurert',
                            'required' => true
                        ),
                        'client_secret' => array(
                            'label' => 'Client Secret konfigurert',
                            'required' => true
                        ),
                        'redirect_uri' => array(
                            'label' => 'Redirect URI satt',
                            'required' => true
                        ),
                        'authorize_endpoint' => array(
                            'label' => 'Authorize Endpoint satt',
                            'required' => true
                        ),
                        'token_endpoint' => array(
                            'label' => 'Token Endpoint satt',
                            'required' => true
                        ),
                        'userinfo_endpoint' => array(
                            'label' => 'Userinfo Endpoint satt',
                            'required' => true
                        )
                    );

                    $all_required_complete = true;
                    foreach ($config_checks as $key => $check) {
                        $is_set = !empty($settings[$key]);
                        if ($check['required'] && !$is_set) {
                            $all_required_complete = false;
                        }
                        $icon = $is_set ? '✅' : ($check['required'] ? '⚠️' : 'ℹ️');
                        $color = $is_set ? '#28a745' : ($check['required'] ? '#f0ad4e' : '#6c757d');
                        echo '<div style="padding: 5px 0;">';
                        echo '<span style="color: ' . $color . ';">' . $icon . '</span> ';
                        echo esc_html($check['label']);
                        echo '</div>';
                    }
                    ?>
                </div>
                <?php if ($all_required_complete): ?>
                    <p style="margin: 10px 0 0 0; color: #28a745; font-weight: 600;">
                        ✓ Alle påkrevde innstillinger er konfigurert
                    </p>
                <?php else: ?>
                    <p style="margin: 10px 0 0 0; color: #f0ad4e; font-weight: 600;">
                        ⚠ Noen påkrevde innstillinger mangler - <a href="?page=feide-wp-auth&tab=settings">konfigurer nå</a>
                    </p>
                <?php endif; ?>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="?page=feide-wp-auth&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    OpenID Innstillinger
                </a>
                <a href="?page=feide-wp-auth&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">
                    Test Autentisering
                </a>
                <a href="?page=feide-wp-auth&tab=mapping" class="nav-tab <?php echo $active_tab === 'mapping' ? 'nav-tab-active' : ''; ?>">
                    Attributt-mapping
                </a>
                <a href="?page=feide-wp-auth&tab=roles" class="nav-tab <?php echo $active_tab === 'roles' ? 'nav-tab-active' : ''; ?>">
                    Rolletildeling
                </a>
                <a href="?page=feide-wp-auth&tab=import-export" class="nav-tab <?php echo $active_tab === 'import-export' ? 'nav-tab-active' : ''; ?>">
                    Import/Eksport
                </a>
                <a href="?page=feide-wp-auth&tab=debug" class="nav-tab <?php echo $active_tab === 'debug' ? 'nav-tab-active' : ''; ?>">
                    Debug
                </a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('feide_wp_auth_settings_group'); ?>

                <?php
                switch ($active_tab) {
                    case 'test':
                        $this->render_test_tab($settings);
                        break;
                    case 'mapping':
                        $this->render_mapping_tab($settings);
                        break;
                    case 'roles':
                        $this->render_roles_tab($settings);
                        break;
                    case 'import-export':
                        $this->render_import_export_tab($settings);
                        break;
                    case 'debug':
                        $this->render_debug_tab($settings);
                        break;
                    default:
                        $this->render_settings_tab($settings);
                        break;
                }
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render innstillinger-fane
     */
    private function render_settings_tab($settings) {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="client_id">
                        Client ID <span class="required">*</span>
                    </label>
                </th>
                <td>
                    <input type="text" id="client_id" name="feide_wp_auth_settings[client_id]"
                           value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text"
                           aria-required="true" required>
                    <p class="description">Client ID fra FEIDE (påkrevd)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="client_secret">
                        Client Secret <span class="required">*</span>
                    </label>
                </th>
                <td>
                    <input type="password" id="client_secret" name="feide_wp_auth_settings[client_secret]"
                           value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text"
                           autocomplete="off" aria-required="true" required>
                    <p class="description">Client Secret fra FEIDE (påkrevd)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="redirect_uri">
                        Redirect / Callback URL <span class="required">*</span>
                    </label>
                </th>
                <td>
                    <input type="text" id="redirect_uri" name="feide_wp_auth_settings[redirect_uri]"
                           value="<?php echo esc_attr($settings['redirect_uri'] ?? site_url('/wp-login.php?feide-auth=callback')); ?>" class="regular-text"
                           aria-required="true" required>
                    <p class="description">Denne URL-en må være registrert hos FEIDE (påkrevd)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="scope">Scope</label>
                </th>
                <td>
                    <input type="text" id="scope" name="feide_wp_auth_settings[scope]"
                           value="<?php echo esc_attr($settings['scope'] ?? 'openid profile email'); ?>" class="regular-text">
                    <p class="description">OAuth scopes (f.eks. openid profile email)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="authorize_endpoint">Authorize Endpoint</label>
                </th>
                <td>
                    <input type="url" id="authorize_endpoint" name="feide_wp_auth_settings[authorize_endpoint]"
                           value="<?php echo esc_attr($settings['authorize_endpoint'] ?? 'https://auth.dataporten.no/oauth/authorization'); ?>" class="regular-text">
                    <button type="button" class="button test-endpoint-btn" data-endpoint="authorize_endpoint">Test</button>
                    <span class="endpoint-status" id="authorize_endpoint_status"></span>
                    <p class="description">OAuth Authorization Endpoint</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="token_endpoint">Access Token Endpoint</label>
                </th>
                <td>
                    <input type="url" id="token_endpoint" name="feide_wp_auth_settings[token_endpoint]"
                           value="<?php echo esc_attr($settings['token_endpoint'] ?? 'https://auth.dataporten.no/oauth/token'); ?>" class="regular-text">
                    <button type="button" class="button test-endpoint-btn" data-endpoint="token_endpoint">Test</button>
                    <span class="endpoint-status" id="token_endpoint_status"></span>
                    <p class="description">OAuth Token Endpoint</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="userinfo_endpoint">Get User Info Endpoint</label>
                </th>
                <td>
                    <input type="url" id="userinfo_endpoint" name="feide_wp_auth_settings[userinfo_endpoint]"
                           value="<?php echo esc_attr($settings['userinfo_endpoint'] ?? 'https://auth.dataporten.no/userinfo'); ?>" class="regular-text">
                    <button type="button" class="button test-endpoint-btn" data-endpoint="userinfo_endpoint">Test</button>
                    <span class="endpoint-status" id="userinfo_endpoint_status"></span>
                    <p class="description">OpenID Connect UserInfo Endpoint</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="groupinfo_endpoint">Group User Info Endpoint</label>
                </th>
                <td>
                    <input type="url" id="groupinfo_endpoint" name="feide_wp_auth_settings[groupinfo_endpoint]"
                           value="<?php echo esc_attr($settings['groupinfo_endpoint'] ?? 'https://groups-api.dataporten.no/groups/me/groups'); ?>" class="regular-text">
                    <button type="button" class="button test-endpoint-btn" data-endpoint="groupinfo_endpoint">Test</button>
                    <span class="endpoint-status" id="groupinfo_endpoint_status"></span>
                    <p class="description">Endpoint for å hente gruppeinformasjon (valgfritt)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="auto_create_users">Automatisk oppretting av brukere</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="auto_create_users" name="feide_wp_auth_settings[auto_create_users]"
                               value="1" <?php checked(!empty($settings['auto_create_users'])); ?>>
                        Opprett automatisk nye brukere ved første innlogging
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="allow_all_authenticated">Tilgangskontroll</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="allow_all_authenticated" name="feide_wp_auth_settings[allow_all_authenticated]"
                               value="1" <?php checked(!empty($settings['allow_all_authenticated'])); ?>>
                        Gi alle autentiserte FEIDE-brukere tilgang (ignorer rolle-regler)
                    </label>
                    <p class="description">Hvis denne er avkrysset, vil alle som logger inn med FEIDE få tilgang uten å måtte oppfylle rolle-kriterier.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="default_role">Standard rolle</label>
                </th>
                <td>
                    <select id="default_role" name="feide_wp_auth_settings[default_role]">
                        <?php
                        $wp_roles = wp_roles()->get_names();
                        $default_role = isset($settings['default_role']) ? $settings['default_role'] : 'subscriber';
                        foreach ($wp_roles as $role_key => $role_name):
                        ?>
                            <option value="<?php echo esc_attr($role_key); ?>" <?php selected($default_role, $role_key); ?>>
                                <?php echo esc_html($role_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Standard rolle for nye brukere (brukes når "Gi alle tilgang" er aktivert eller ingen rolle-regler er definert)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="redirect_after_login">Redirect etter innlogging</label>
                </th>
                <td>
                    <input type="url" id="redirect_after_login" name="feide_wp_auth_settings[redirect_after_login]"
                           value="<?php echo esc_attr($settings['redirect_after_login'] ?? home_url()); ?>" class="regular-text">
                    <p class="description">Hvor skal brukere sendes etter vellykket innlogging? Standard er hjemmesiden. Du kan også bruke <code><?php echo home_url('/min-side'); ?></code> eller lignende.</p>
                </td>
            </tr>
        </table>

        <h2 style="margin-top: 30px;">Debug-innstillinger</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="enable_debug_logging">Aktiver debug-logging</label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" id="enable_debug_logging" name="feide_wp_auth_settings[enable_debug_logging]"
                               value="1" <?php checked(!empty($settings['enable_debug_logging'])); ?>>
                        Lagre debug-informasjon om innlogginger og rolle-evalueringer
                    </label>
                    <p class="description">Når aktivert, lagres detaljert informasjon om hver innlogging (attributter, rolle-evalueringer, osv.) som kan sees i Debug-fanen. Deaktiver dette i produksjon av personvern- og sikkerhetshensyn.</p>
                </td>
            </tr>
        </table>

        <?php submit_button('Lagre innstillinger'); ?>
        <?php
    }

    /**
     * Render test-fane
     */
    private function render_test_tab($settings) {
        $test_result = get_transient('feide_test_result');
        $test_error = get_transient('feide_test_error');

        if ($test_error) {
            delete_transient('feide_test_error');
            echo '<div class="notice notice-error"><p>Feil: ' . esc_html($test_error) . '</p></div>';
        }

        if (isset($_GET['test-success']) && $test_result) {
            echo '<div class="notice notice-success"><p>Test vellykket! Se attributter nedenfor.</p></div>';
        }

        ?>
        <h2>Test FEIDE-autentisering</h2>
        <p>Klikk på knappen nedenfor for å teste innlogging med FEIDE. Du vil bli omdirigert til FEIDE for autentisering, og deretter vises alle attributter som mottas.</p>

        <?php if (empty($settings['client_id']) || empty($settings['client_secret'])): ?>
            <div class="notice notice-warning">
                <p>Du må konfigurere Client ID og Client Secret før du kan teste autentiseringen.</p>
            </div>
        <?php else: ?>
            <p>
                <a href="<?php echo esc_url($this->get_test_auth_url($settings)); ?>" class="button button-primary">
                    Test FEIDE-innlogging
                </a>
            </p>
        <?php endif; ?>

        <?php if ($test_result): ?>
            <h3>Mottatte attributter</h3>
            <p><strong>Bruk attributt-stiene nedenfor direkte i rolle-regler!</strong> Kopier "Attributt-sti" til "Attributt"-feltet i rolle-reglene.</p>

            <div class="feide-test-results">
                <?php $this->render_flat_attributes_table($test_result); ?>
            </div>

            <details style="margin-top: 20px;">
                <summary><strong>Vis fullstendig JSON-struktur</strong></summary>
                <pre style="background: #f5f5f5; padding: 15px; overflow: auto; max-height: 600px;"><?php echo esc_html(json_encode($test_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            </details>
            <?php delete_transient('feide_test_result'); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Generer test-autentiserings-URL
     */
    private function get_test_auth_url($settings) {
        // Generer kryptografisk sikker tilfeldig state-parameter
        $state = Feide_State_Manager::generate_state(true);

        $params = array(
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'],
            'response_type' => 'code',
            'scope' => $settings['scope'],
            'state' => $state
        );

        return $settings['authorize_endpoint'] . '?' . http_build_query($params);
    }

    /**
     * Flate ut nested array til attributt-stier
     */
    private function flatten_attributes($data, $prefix = '', &$result = array(), $skip_keys = array()) {
        foreach ($data as $key => $value) {
            // Hopp over spesielle nøkler (f.eks. _meta)
            if (in_array($key, $skip_keys)) {
                continue;
            }

            $full_key = $prefix ? $prefix . ':' . $key : $key;

            if (is_array($value) && !empty($value)) {
                // Sjekk om dette er et numerisk array (liste) eller assosiativt array
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // Numerisk array - vis hvert element
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $this->flatten_attributes($item, $full_key . ':' . $index, $result, $skip_keys);
                        } else {
                            $result[] = array(
                                'path' => $full_key . ':' . $index,
                                'value' => $item,
                                'type' => gettype($item)
                            );
                        }
                    }
                } else {
                    // Assosiativt array - fortsett å flate ut
                    $this->flatten_attributes($value, $full_key, $result, $skip_keys);
                }
            } else {
                // Enkeltverdi
                $result[] = array(
                    'path' => $full_key,
                    'value' => is_bool($value) ? ($value ? 'true' : 'false') : (empty($value) && $value !== '0' && $value !== 0 ? '(tom)' : $value),
                    'type' => gettype($value)
                );
            }
        }

        return $result;
    }

    /**
     * Render attributter som flat tabell med stier
     */
    private function render_flat_attributes_table($data) {
        // Ekstraher token-info hvis den finnes
        $token_info = isset($data['_meta']) ? $data['_meta'] : null;

        $flattened = array();
        $this->flatten_attributes($data, '', $flattened, array('_meta'));

        if (empty($flattened)) {
            echo '<p>Ingen attributter mottatt.</p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 40%;">Attributt-sti (bruk i rolle-regler)</th>
                    <th style="width: 50%;">Verdi</th>
                    <th style="width: 10%;">Type</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($flattened as $item): ?>
                <tr>
                    <td>
                        <code style="background: #f0f0f1; padding: 3px 6px; border-radius: 3px; font-size: 13px; user-select: all;">
                            <?php echo esc_html($item['path']); ?>
                        </code>
                    </td>
                    <td style="word-break: break-word;">
                        <?php echo esc_html($item['value']); ?>
                    </td>
                    <td>
                        <small><?php echo esc_html($item['type']); ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <em>Tips: Klikk på en attributt-sti for å markere hele teksten, deretter kopier den (Ctrl+C / Cmd+C).</em>
        </p>

        <?php if ($token_info): ?>
        <details style="margin-top: 15px;">
            <summary><strong>Token-informasjon (ikke brukt i rolle-regler)</strong></summary>
            <table class="wp-list-table widefat" style="margin-top: 10px;">
                <tbody>
                    <?php foreach ($token_info as $key => $value): ?>
                    <tr>
                        <td><strong><?php echo esc_html($key); ?></strong></td>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>
        <?php
    }

    /**
     * Render mapping-fane
     */
    private function render_mapping_tab($settings) {
        $attribute_mapping = $settings['attribute_mapping'] ?? array(
            'username' => 'sub',
            'email' => 'email',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
            'display_name' => 'name'
        );

        ?>
        <h2>Attributt-mapping</h2>
        <p>Map FEIDE-attributter til WordPress-brukerfelter. Bruk kolon (:) for nested attributter (f.eks. user:id).</p>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="map_username">Brukernavn</label>
                </th>
                <td>
                    <input type="text" id="map_username" name="feide_wp_auth_settings[attribute_mapping][username]"
                           value="<?php echo esc_attr($attribute_mapping['username'] ?? 'sub'); ?>" class="regular-text">
                    <p class="description">FEIDE-attributt for WordPress brukernavn (påkrevd)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="map_email">E-post</label>
                </th>
                <td>
                    <input type="text" id="map_email" name="feide_wp_auth_settings[attribute_mapping][email]"
                           value="<?php echo esc_attr($attribute_mapping['email'] ?? 'email'); ?>" class="regular-text">
                    <p class="description">FEIDE-attributt for e-postadresse</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="map_first_name">Fornavn</label>
                </th>
                <td>
                    <input type="text" id="map_first_name" name="feide_wp_auth_settings[attribute_mapping][first_name]"
                           value="<?php echo esc_attr($attribute_mapping['first_name'] ?? 'given_name'); ?>" class="regular-text">
                    <p class="description">FEIDE-attributt for fornavn</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="map_last_name">Etternavn</label>
                </th>
                <td>
                    <input type="text" id="map_last_name" name="feide_wp_auth_settings[attribute_mapping][last_name]"
                           value="<?php echo esc_attr($attribute_mapping['last_name'] ?? 'family_name'); ?>" class="regular-text">
                    <p class="description">FEIDE-attributt for etternavn</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="map_display_name">Visningsnavn</label>
                </th>
                <td>
                    <input type="text" id="map_display_name" name="feide_wp_auth_settings[attribute_mapping][display_name]"
                           value="<?php echo esc_attr($attribute_mapping['display_name'] ?? 'name'); ?>" class="regular-text">
                    <p class="description">FEIDE-attributt for visningsnavn</p>
                </td>
            </tr>
        </table>

        <?php submit_button('Lagre mapping'); ?>
        <?php
    }

    /**
     * Render rolle-fane
     */
    private function render_roles_tab($settings) {
        $role_mappings = $settings['role_mappings'] ?? array();
        $wp_roles = wp_roles()->get_names();

        ?>
        <h2>Rolletildeling basert på FEIDE-attributter</h2>
        <p>Definer kriterier for hvilke brukere som skal få tilgang og hvilke roller de skal tildeles.</p>

        <div class="notice notice-info" style="padding: 10px; margin: 15px 0;">
            <p><strong>💡 Tips: Wildcard-støtte</strong></p>
            <p>Du kan bruke <code>*</code> som wildcard i attributt-stier for å matche alle elementer i et array.</p>
            <p><strong>Eksempler:</strong></p>
            <ul style="margin-left: 20px;">
                <li><code>groups:*:id</code> - Matcher hvis MINST ÉN gruppe har angitt ID</li>
                <li><code>groups:*:displayName</code> - Matcher hvis MINST ÉN gruppe har angitt navn</li>
                <li><code>user:orgs:*:role</code> - Matcher hvis brukeren har angitt rolle i MINST ÉN organisasjon</li>
            </ul>
            <p><em>Dette gjør det enkelt å sjekke medlemskap i grupper uten å vite eksakt indeks!</em></p>
        </div>

        <div id="role-mappings-container">
            <?php
            if (empty($role_mappings)) {
                $role_mappings = array(
                    array(
                        'role' => 'subscriber',
                        'operator' => 'AND',
                        'criteria' => array(
                            array('attribute' => '', 'comparison' => 'equals', 'value' => '')
                        )
                    )
                );
            }

            foreach ($role_mappings as $index => $mapping):
            ?>
            <div class="role-mapping-item" data-index="<?php echo $index; ?>">
                <h3><?php echo !empty($mapping['name']) ? esc_html($mapping['name']) : 'Rolleregel #' . ($index + 1); ?>
                    <button type="button" class="button remove-role-mapping">Fjern</button>
                </h3>

                <table class="form-table">
                    <tr>
                        <th scope="row">Navn på regel</th>
                        <td>
                            <input type="text" name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][name]"
                                   value="<?php echo esc_attr($mapping['name'] ?? ''); ?>"
                                   placeholder="F.eks. 'Studenter' eller 'Ansatte'"
                                   class="regular-text rule-name-input">
                            <p class="description">Gi regelen et beskrivende navn (valgfritt)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WordPress-rolle</th>
                        <td>
                            <select name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][role]" class="regular-text">
                                <?php foreach ($wp_roles as $role_key => $role_name): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php selected($mapping['role'], $role_key); ?>>
                                        <?php echo esc_html($role_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kriterier-operator</th>
                        <td>
                            <label>
                                <input type="radio" name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][operator]"
                                       value="AND" <?php checked($mapping['operator'] ?? 'AND', 'AND'); ?>>
                                AND (alle kriterier må være oppfylt)
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][operator]"
                                       value="OR" <?php checked($mapping['operator'] ?? 'AND', 'OR'); ?>>
                                OR (minst ett kriterium må være oppfylt)
                            </label>
                        </td>
                    </tr>
                </table>

                <h4 id="criteria-heading-<?php echo $index; ?>">Kriterier</h4>
                <div class="criteria-container" data-mapping-index="<?php echo $index; ?>" role="group" aria-labelledby="criteria-heading-<?php echo $index; ?>">
                    <?php
                    $criteria = $mapping['criteria'] ?? array(array('attribute' => '', 'comparison' => 'equals', 'value' => ''));
                    foreach ($criteria as $crit_index => $criterion):
                    ?>
                    <div class="criterion-item" role="group" aria-label="Kriterium <?php echo $crit_index + 1; ?>">
                        <input type="text"
                               name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][criteria][<?php echo $crit_index; ?>][attribute]"
                               placeholder="Attributt (f.eks. groups:*:id eller user:email)"
                               value="<?php echo esc_attr($criterion['attribute']); ?>"
                               class="regular-text"
                               aria-label="Attributt-sti"
                               title="Bruk * som wildcard for å matche alle elementer i et array. Eksempel: groups:*:id matcher id fra alle grupper">

                        <select name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][criteria][<?php echo $crit_index; ?>][comparison]"
                                aria-label="Sammenligningsoperator">
                            <option value="equals" <?php selected($criterion['comparison'], 'equals'); ?>>Er lik</option>
                            <option value="contains" <?php selected($criterion['comparison'], 'contains'); ?>>Inneholder</option>
                            <option value="starts_with" <?php selected($criterion['comparison'], 'starts_with'); ?>>Starter med</option>
                            <option value="ends_with" <?php selected($criterion['comparison'], 'ends_with'); ?>>Slutter med</option>
                            <option value="not_equals" <?php selected($criterion['comparison'], 'not_equals'); ?>>Er ikke lik</option>
                        </select>

                        <input type="text"
                               name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][criteria][<?php echo $crit_index; ?>][value]"
                               placeholder="Verdi"
                               value="<?php echo esc_attr($criterion['value']); ?>"
                               class="regular-text"
                               aria-label="Forventet verdi">

                        <button type="button" class="button remove-criterion" aria-label="Fjern dette kriteriet">Fjern</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="button" class="button add-criterion" data-mapping-index="<?php echo $index; ?>" aria-describedby="add-criterion-help-<?php echo $index; ?>">
                        Legg til kriterium
                    </button>
                </p>
            </div>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="button" class="button" id="add-role-mapping">Legg til ny rolleregel</button>
        </p>

        <?php submit_button('Lagre rolletildeling'); ?>
        <?php
    }

    /**
     * Render debug-fane
     */
    private function render_debug_tab($settings) {
        // Håndter sletting av debug-data
        if (isset($_POST['clear_debug_data']) && check_admin_referer('feide_clear_debug', 'feide_debug_nonce')) {
            $this->clear_debug_data();
            echo '<div class="notice notice-success"><p>Debug-data er slettet.</p></div>';
        }

        $debug_enabled = !empty($settings['enable_debug_logging']);
        ?>
        <h2>Debug-informasjon</h2>

        <?php if (!$debug_enabled): ?>
        <div class="notice notice-warning">
            <p><strong>⚠️ Debug-logging er deaktivert</strong></p>
            <p>For å samle debug-informasjon må du først aktivere debug-logging i <a href="?page=feide-wp-auth&tab=settings">OpenID Innstillinger</a>.</p>
        </div>
        <?php else: ?>
        <div class="notice notice-info">
            <p><strong>ℹ️ Debug-logging er aktivert</strong></p>
            <p>Informasjon om innlogginger og rolle-evalueringer lagres. Deaktiver dette i produksjon av personvern- og sikkerhetshensyn.</p>
        </div>
        <?php endif; ?>

        <div style="margin: 20px 0;">
            <form method="post" action="" style="display: inline;">
                <?php wp_nonce_field('feide_clear_debug', 'feide_debug_nonce'); ?>
                <button type="submit" name="clear_debug_data" class="button" onclick="return confirm('Er du sikker på at du vil slette all debug-data?');">
                    Slett all debug-data
                </button>
            </form>
            <p class="description">Sletter all lagret debug-informasjon (attributter, rolle-evalueringer, osv.) fra databasen.</p>
        </div>

        <p>Denne fanen viser alle lagrede innstillinger og siste feilmeldinger. Dette er nyttig for å feilsøke tilgangsproblemer.</p>

        <?php
        $last_attributes = get_transient('feide_last_attributes');
        if ($last_attributes):
        ?>
        <h3>Siste attributter mottatt fra FEIDE</h3>
        <div style="background: #d1ecf1; padding: 15px; border: 1px solid #0c5460; margin-bottom: 20px;">
            <p><strong>Tidspunkt:</strong> <?php echo esc_html($last_attributes['timestamp']); ?></p>
            <p><strong>Bruk denne informasjonen til å konfigurere attributt-mapping og rolle-regler!</strong></p>

            <h4>User Info (fra UserInfo endpoint):</h4>
            <div style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;">
                <pre><?php echo esc_html(print_r($last_attributes['user_info'], true)); ?></pre>
            </div>

            <?php if (!empty($last_attributes['group_info'])): ?>
            <h4>Group Info (fra Groups endpoint):</h4>
            <div style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;">
                <pre><?php echo esc_html(print_r($last_attributes['group_info'], true)); ?></pre>
            </div>
            <?php endif; ?>

            <h4>All Attributes (kombinert data brukt i rolle-sjekk):</h4>
            <div style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;">
                <pre><?php echo esc_html(print_r($last_attributes['all_attributes'], true)); ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <?php
        $all_criteria_checks = get_transient('feide_all_criteria_checks');
        if ($all_criteria_checks):
        ?>
        <h3>Evaluering av alle rolle-regler (detaljert)</h3>
        <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffc107; margin-bottom: 20px;">
            <p><strong>Dette viser nøyaktig hvordan ALLE rolle-regler ble evaluert:</strong></p>

            <?php foreach ($all_criteria_checks as $rule_check): ?>
                <div style="margin-bottom: 20px; padding: 15px; background: <?php echo $rule_check['criteria_met'] ? '#d4edda' : '#f8d7da'; ?>; border-radius: 5px;">
                    <h4 style="margin-top: 0;">
                        <?php echo esc_html($rule_check['rule_name']); ?>
                        → Rolle: <strong><?php echo esc_html($rule_check['role']); ?></strong>
                        → Operator: <strong><?php echo esc_html($rule_check['operator']); ?></strong>
                        → Resultat: <strong><?php echo $rule_check['criteria_met'] ? '✅ MATCHET' : '❌ MATCHET IKKE'; ?></strong>
                    </h4>

                    <table class="wp-list-table widefat fixed striped" style="background: #fff;">
                        <thead>
                            <tr>
                                <th style="width: 25%;">Attributt</th>
                                <th style="width: 25%;">Faktisk verdi</th>
                                <th style="width: 10%;">Type</th>
                                <th style="width: 20%;">Forventet verdi</th>
                                <th style="width: 10%;">Sammenligning</th>
                                <th style="width: 10%;">Resultat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rule_check['comparisons'] as $check): ?>
                            <tr style="background: <?php echo $check['result'] === 'MATCH' ? '#d4edda' : '#f8d7da'; ?>">
                                <td><code><?php echo esc_html($check['attribute_path']); ?></code></td>
                                <td><code style="word-break: break-all;"><?php echo esc_html(is_array($check['actual_value']) ? json_encode($check['actual_value'], JSON_UNESCAPED_UNICODE) : print_r($check['actual_value'], true)); ?></code></td>
                                <td><?php echo esc_html($check['actual_type']); ?></td>
                                <td><code><?php echo esc_html($check['expected_value']); ?></code></td>
                                <td><?php echo esc_html($check['comparison']); ?></td>
                                <td><strong><?php echo esc_html($check['result']); ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <p><em>Grønn bakgrunn = regel matchet / kriterium matchet, rød bakgrunn = regel matchet ikke / kriterium matchet ikke</em></p>
        </div>
        <?php endif; ?>

        <h3>OAuth Client Credentials (verifisering)</h3>
        <div style="background: #e7f3ff; padding: 15px; border: 1px solid #2271b1; margin-bottom: 20px;">
            <p><strong>Bruk denne informasjonen til å verifisere at dine credentials er lagret riktig:</strong></p>
            <table class="wp-list-table widefat">
                <tbody>
                    <tr>
                        <td><strong>Client ID</strong></td>
                        <td>
                            <?php if (!empty($settings['client_id'])): ?>
                                Lengde: <?php echo strlen($settings['client_id']); ?> tegn<br>
                                Verdi: <code><?php echo esc_html($settings['client_id']); ?></code>
                            <?php else: ?>
                                <span style="color: #dc3232;">IKKE SATT</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Client Secret</strong></td>
                        <td>
                            <?php if (!empty($settings['client_secret'])): ?>
                                Lengde: <?php echo strlen($settings['client_secret']); ?> tegn<br>
                                Preview: <code><?php echo esc_html(substr($settings['client_secret'], 0, 8) . '...' . substr($settings['client_secret'], -4)); ?></code>
                            <?php else: ?>
                                <span style="color: #dc3232;">IKKE SATT</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p><em><strong>Forventet format:</strong> Client ID og Secret skal begge være UUID-er på formatet xxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx (36 tegn)</em></p>
            <?php if (!empty($settings['client_id']) && strlen($settings['client_id']) !== 36): ?>
                <p style="color: #dc3232;"><strong>⚠️ ADVARSEL:</strong> Client ID har ikke forventet lengde (36 tegn). Den kan være feil!</p>
            <?php endif; ?>
            <?php if (!empty($settings['client_secret']) && strlen($settings['client_secret']) !== 36): ?>
                <p style="color: #dc3232;"><strong>⚠️ ADVARSEL:</strong> Client Secret har ikke forventet lengde (36 tegn). Den kan være feil!</p>
            <?php endif; ?>
        </div>

        <h3>Lagrede innstillinger (komplett)</h3>
        <div style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto;">
            <pre><?php echo esc_html(print_r($settings, true)); ?></pre>
        </div>

        <?php
        $debug_info = get_transient('feide_access_denied_debug');
        if ($debug_info):
        ?>
        <h3>Siste tilgangsnekting (debug-info)</h3>
        <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffc107;">
            <p><strong>Tidspunkt:</strong> <?php echo esc_html($debug_info['timestamp']); ?></p>

            <h4>Mottatte attributter fra FEIDE:</h4>
            <div style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;">
                <pre><?php echo esc_html(print_r($debug_info['attributes'], true)); ?></pre>
            </div>

            <h4>Rolle-regler som ble sjekket:</h4>
            <div style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;">
                <pre><?php echo esc_html(print_r($debug_info['role_mappings'], true)); ?></pre>
            </div>

            <p>
                <a href="<?php echo admin_url('admin.php?page=feide-wp-auth&tab=roles'); ?>" class="button button-primary">
                    Gå til rolletildeling
                </a>
                <a href="<?php echo admin_url('admin.php?page=feide-wp-auth&tab=settings'); ?>" class="button">
                    Aktiver "Tillat alle autentiserte brukere"
                </a>
            </p>
        </div>
        <?php else: ?>
        <p><em>Ingen debug-informasjon tilgjengelig. Debug-info lagres når en pålogging nektes tilgang.</em></p>
        <?php endif; ?>

        <h3>Hurtigfiks</h3>
        <div style="background: #d4edda; padding: 15px; border: 1px solid #28a745; margin-top: 20px;">
            <h4>Hvis du får "Tilgang nektet":</h4>
            <ol>
                <li>Gå til <a href="<?php echo admin_url('admin.php?page=feide-wp-auth&tab=settings'); ?>">OpenID Innstillinger</a></li>
                <li>Kryss av for "Gi alle autentiserte FEIDE-brukere tilgang"</li>
                <li>Velg en "Standard rolle" (f.eks. Subscriber)</li>
                <li>Klikk "Lagre innstillinger"</li>
                <li>Prøv å logge inn igjen</li>
            </ol>
            <p><strong>Eller:</strong> Konfigurer rolle-regler i <a href="<?php echo admin_url('admin.php?page=feide-wp-auth&tab=roles'); ?>">Rolletildeling</a> basert på attributtene over.</p>
        </div>
        <?php
    }

    /**
     * Render import/eksport-fane
     */
    private function render_import_export_tab($settings) {
        ?>
        <h2>Import og Eksport av Innstillinger</h2>
        <p>Eksporter og importer plugin-innstillinger for å migrere mellom miljøer eller lage backups.</p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px;">

            <!-- EKSPORT SEKSJONEN -->
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3 style="margin-top: 0;">📤 Eksporter Innstillinger</h3>
                <p>Last ned dine nåværende innstillinger som en JSON-fil.</p>

                <div style="background: #f0f6fc; border-left: 4px solid #0969da; padding: 12px; margin: 15px 0;">
                    <strong>Velg hva som skal eksporteres:</strong>
                    <div style="margin-top: 10px;">
                        <label style="display: block; margin: 8px 0;">
                            <input type="checkbox" id="export_openid_settings" checked>
                            OpenID Connect innstillinger (endpoints, scope, etc.)
                        </label>
                        <label style="display: block; margin: 8px 0;">
                            <input type="checkbox" id="export_credentials">
                            <strong style="color: #d63d00;">Client ID og Secret</strong>
                            <span style="color: #666; font-size: 0.9em;">(Sensitiv data - vær forsiktig!)</span>
                        </label>
                        <label style="display: block; margin: 8px 0;">
                            <input type="checkbox" id="export_attribute_mapping" checked>
                            Attributt-mapping
                        </label>
                        <label style="display: block; margin: 8px 0;">
                            <input type="checkbox" id="export_role_rules" checked>
                            Rolletildelingsregler
                        </label>
                        <label style="display: block; margin: 8px 0;">
                            <input type="checkbox" id="export_user_settings" checked>
                            Brukerinnstillinger (auto-create, default role, etc.)
                        </label>
                    </div>
                </div>

                <div id="credentials-warning" style="display: none; background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0;">
                    <strong>⚠️ Sikkerhetsadvarsel:</strong>
                    <p style="margin: 5px 0 0 0;">Client Secret er sensitiv informasjon. Ikke del denne filen offentlig eller lagre den usikkert.</p>
                </div>

                <button type="button" id="export-settings-btn" class="button button-primary" style="margin-top: 15px;">
                    📥 Last ned innstillinger (JSON)
                </button>
            </div>

            <!-- IMPORT SEKSJONEN -->
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3 style="margin-top: 0;">📥 Importer Innstillinger</h3>
                <p>Last opp en tidligere eksportert JSON-fil for å gjenopprette innstillinger.</p>

                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0;">
                    <strong>⚠️ Viktig:</strong>
                    <p style="margin: 5px 0 0 0;">Import vil <strong>overskrive</strong> eksisterende innstillinger. En backup opprettes automatisk.</p>
                </div>

                <div style="margin: 20px 0;">
                    <label for="import-file-input" style="display: block; margin-bottom: 10px; font-weight: 600;">
                        Velg JSON-fil:
                    </label>
                    <input type="file" id="import-file-input" accept=".json" style="margin-bottom: 15px;">
                </div>

                <div id="import-preview" style="display: none; background: #f0f6fc; border: 1px solid #0969da; padding: 15px; margin: 15px 0; max-height: 300px; overflow-y: auto;">
                    <h4 style="margin-top: 0;">Forhåndsvisning av import:</h4>
                    <div id="import-preview-content"></div>
                </div>

                <div style="margin-top: 15px;">
                    <button type="button" id="import-settings-btn" class="button button-primary" disabled>
                        📤 Importer innstillinger
                    </button>
                    <button type="button" id="cancel-import-btn" class="button" style="display: none; margin-left: 10px;">
                        Avbryt
                    </button>
                </div>

                <div id="import-result" style="margin-top: 15px;"></div>
            </div>
        </div>

        <!-- URL REPLACEMENT TOOL -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 30px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin-top: 0;">🔄 URL Erstatningsverktøy</h3>
            <p>Bytt ut URL-er i innstillingene (nyttig ved migrering mellom dev/staging/prod).</p>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; align-items: end;">
                <div>
                    <label for="url-find" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        Finn URL:
                    </label>
                    <input type="text" id="url-find" class="regular-text" placeholder="https://dev.example.com" style="width: 100%;">
                </div>
                <div>
                    <label for="url-replace" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        Erstatt med:
                    </label>
                    <input type="text" id="url-replace" class="regular-text" placeholder="https://prod.example.com" style="width: 100%;">
                </div>
                <div>
                    <button type="button" id="url-replace-btn" class="button">
                        Erstatt URL-er
                    </button>
                </div>
            </div>

            <div id="url-replace-result" style="margin-top: 15px;"></div>
        </div>

        <!-- BACKUP SEKSJONEN -->
        <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 30px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin-top: 0;">💾 Automatiske Backups</h3>
            <p>Før hver import opprettes en automatisk backup som kan gjenopprettes.</p>

            <?php
            $backup = get_option('feide_wp_auth_settings_backup');
            $backup_time = get_option('feide_wp_auth_settings_backup_time');
            ?>

            <?php if ($backup && $backup_time): ?>
                <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px; margin: 15px 0;">
                    <strong>✅ Backup tilgjengelig</strong><br>
                    Opprettet: <?php echo date('d.m.Y H:i:s', $backup_time); ?>
                    <button type="button" id="restore-backup-btn" class="button" style="margin-left: 15px;">
                        🔄 Gjenopprett backup
                    </button>
                    <button type="button" id="download-backup-btn" class="button" style="margin-left: 5px;">
                        📥 Last ned backup
                    </button>
                    <button type="button" id="delete-backup-btn" class="button" style="margin-left: 5px;">
                        🗑️ Slett backup
                    </button>
                </div>
            <?php else: ?>
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px;">
                    <strong>ℹ️ Ingen backup tilgjengelig</strong><br>
                    En backup vil opprettes automatisk neste gang du importerer innstillinger.
                </div>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {

            // Toggle credentials warning
            $('#export_credentials').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#credentials-warning').slideDown();
                } else {
                    $('#credentials-warning').slideUp();
                }
            });

            // EKSPORT FUNKSJONALITET
            $('#export-settings-btn').on('click', function() {
                var $btn = $(this);
                var originalText = $btn.html();

                // Disable button and show loading state
                $btn.prop('disabled', true);
                $btn.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Eksporterer...');

                var exportOptions = {
                    openid_settings: $('#export_openid_settings').is(':checked'),
                    credentials: $('#export_credentials').is(':checked'),
                    attribute_mapping: $('#export_attribute_mapping').is(':checked'),
                    role_rules: $('#export_role_rules').is(':checked'),
                    user_settings: $('#export_user_settings').is(':checked')
                };

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'feide_export_settings',
                        nonce: '<?php echo wp_create_nonce('feide_import_export'); ?>',
                        options: exportOptions
                    },
                    success: function(response) {
                        if (response.success) {
                            // Opprett og last ned fil
                            var dataStr = JSON.stringify(response.data, null, 2);
                            var dataBlob = new Blob([dataStr], {type: 'application/json'});
                            var url = URL.createObjectURL(dataBlob);
                            var link = document.createElement('a');
                            link.href = url;
                            link.download = 'feide-settings-' + new Date().toISOString().split('T')[0] + '.json';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            URL.revokeObjectURL(url);

                            // Show success notification instead of alert
                            $('<div class="notice notice-success is-dismissible" style="margin: 15px 0;"><p>✅ Innstillinger eksportert!</p></div>').insertAfter($btn);
                        } else {
                            alert('Feil ved eksport: ' + response.data);
                        }
                        // Re-enable button
                        $btn.prop('disabled', false);
                        $btn.html(originalText);
                    },
                    error: function() {
                        alert('Feil ved kommunikasjon med serveren.');
                        // Re-enable button
                        $btn.prop('disabled', false);
                        $btn.html(originalText);
                    }
                });
            });

            // IMPORT FUNKSJONALITET - Fil valgt
            $('#import-file-input').on('change', function(e) {
                var file = e.target.files[0];
                if (!file) return;

                var reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        var data = JSON.parse(e.target.result);
                        displayImportPreview(data);
                        $('#import-settings-btn').prop('disabled', false);
                        $('#cancel-import-btn').show();
                    } catch(err) {
                        alert('Ugyldig JSON-fil: ' + err.message);
                        $('#import-file-input').val('');
                    }
                };
                reader.readAsText(file);
            });

            // Vis forhåndsvisning av import
            function displayImportPreview(data) {
                var html = '<ul style="margin: 0; padding-left: 20px;">';

                if (data.client_id) html += '<li>Client ID: <code>' + data.client_id.substring(0, 20) + '...</code></li>';
                if (data.client_secret) html += '<li>Client Secret: <strong style="color: #d63d00;">JA (sensitiv data)</strong></li>';
                if (data.redirect_uri) html += '<li>Redirect URI: <code>' + data.redirect_uri + '</code></li>';
                if (data.attribute_mapping) html += '<li>Attributt-mapping: <strong>' + Object.keys(data.attribute_mapping).length + ' felt</strong></li>';
                if (data.role_rules) html += '<li>Rolle-regler: <strong>' + data.role_rules.length + ' regler</strong></li>';
                if (data.default_role) html += '<li>Standard rolle: <code>' + data.default_role + '</code></li>';

                html += '</ul>';
                $('#import-preview-content').html(html);
                $('#import-preview').slideDown();
            }

            // Avbryt import
            $('#cancel-import-btn').on('click', function() {
                $('#import-file-input').val('');
                $('#import-preview').slideUp();
                $('#import-settings-btn').prop('disabled', true);
                $(this).hide();
                $('#import-result').html('');
            });

            // IMPORTER INNSTILLINGER
            $('#import-settings-btn').on('click', function() {
                if (!confirm('Dette vil overskrive nåværende innstillinger. En backup vil opprettes automatisk. Fortsette?')) {
                    return;
                }

                var $btn = $(this);
                var originalText = $btn.html();

                // Disable button and show loading state
                $btn.prop('disabled', true);
                $btn.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Importerer...');
                $('#import-result').html('');

                var file = $('#import-file-input')[0].files[0];
                var reader = new FileReader();

                reader.onload = function(e) {
                    var settings = e.target.result;

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'feide_import_settings',
                            nonce: '<?php echo wp_create_nonce('feide_import_export'); ?>',
                            settings: settings
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#import-result').html('<div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px; margin-top: 15px;"><strong>✅ Innstillinger importert!</strong><br>Siden oppdateres om 2 sekunder...</div>');
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                $('#import-result').html('<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; margin-top: 15px;"><strong>❌ Feil:</strong> ' + response.data + '</div>');
                                // Re-enable button on error
                                $btn.prop('disabled', false);
                                $btn.html(originalText);
                            }
                        },
                        error: function() {
                            $('#import-result').html('<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px; margin-top: 15px;"><strong>❌ Feil ved kommunikasjon med serveren.</strong></div>');
                            // Re-enable button on error
                            $btn.prop('disabled', false);
                            $btn.html(originalText);
                        }
                    });
                };
                reader.readAsText(file);
            });

            // URL REPLACEMENT TOOL
            $('#url-replace-btn').on('click', function() {
                var $btn = $(this);
                var originalText = $btn.html();
                var findUrl = $('#url-find').val();
                var replaceUrl = $('#url-replace').val();

                if (!findUrl || !replaceUrl) {
                    alert('Vennligst fyll ut begge URL-feltene.');
                    return;
                }

                if (!confirm('Dette vil erstatte "' + findUrl + '" med "' + replaceUrl + '" i alle innstillinger. Fortsette?')) {
                    return;
                }

                // Disable button and show loading state
                $btn.prop('disabled', true);
                $btn.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Erstatter...');
                $('#url-replace-result').html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'feide_replace_urls',
                        nonce: '<?php echo wp_create_nonce('feide_import_export'); ?>',
                        find: findUrl,
                        replace: replaceUrl
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#url-replace-result').html('<div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px;"><strong>✅ URL-er erstattet!</strong><br>' + response.data.message + '<br>Siden oppdateres om 2 sekunder...</div>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $('#url-replace-result').html('<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px;"><strong>❌ Feil:</strong> ' + response.data + '</div>');
                            // Re-enable button on error
                            $btn.prop('disabled', false);
                            $btn.html(originalText);
                        }
                    },
                    error: function() {
                        $('#url-replace-result').html('<div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 12px;"><strong>❌ Feil ved kommunikasjon med serveren.</strong></div>');
                        // Re-enable button on error
                        $btn.prop('disabled', false);
                        $btn.html(originalText);
                    }
                });
            });

            // RESTORE BACKUP
            $('#restore-backup-btn').on('click', function() {
                if (!confirm('Dette vil gjenopprette innstillingene fra backup og overskrive nåværende innstillinger. Fortsette?')) {
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'feide_restore_backup',
                        nonce: '<?php echo wp_create_nonce('feide_import_export'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Backup gjenopprettet! Siden oppdateres...');
                            location.reload();
                        } else {
                            alert('Feil ved gjenoppretting: ' + response.data);
                        }
                    }
                });
            });

            // DOWNLOAD BACKUP
            $('#download-backup-btn').on('click', function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'feide_download_backup',
                        nonce: '<?php echo wp_create_nonce('feide_import_export'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var dataStr = JSON.stringify(response.data, null, 2);
                            var dataBlob = new Blob([dataStr], {type: 'application/json'});
                            var url = URL.createObjectURL(dataBlob);
                            var link = document.createElement('a');
                            link.href = url;
                            link.download = 'feide-backup-' + new Date().toISOString().split('T')[0] + '.json';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            URL.revokeObjectURL(url);
                        } else {
                            alert('Feil ved nedlasting av backup: ' + response.data);
                        }
                    }
                });
            });

            // DELETE BACKUP
            $('#delete-backup-btn').on('click', function() {
                if (!confirm('Er du sikker på at du vil slette backupen?')) {
                    return;
                }

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'feide_delete_backup',
                        nonce: '<?php echo wp_create_nonce('feide_import_export'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Backup slettet!');
                            location.reload();
                        } else {
                            alert('Feil ved sletting: ' + response.data);
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Slett all debug-data
     */
    private function clear_debug_data() {
        global $wpdb;

        // Slett alle FEIDE-relaterte transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_feide_%' OR option_name LIKE '_transient_timeout_feide_%'");

        if (WP_DEBUG) {
            error_log('FEIDE Auth: All debug data cleared');
        }
    }

    /**
     * AJAX: Eksporter innstillinger
     */
    public function ajax_export_settings() {
        check_ajax_referer('feide_import_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        $settings = get_option('feide_wp_auth_settings', array());
        $options = isset($_POST['options']) ? $_POST['options'] : array();

        $export = array();
        $export['_export_version'] = '2.4.0';
        $export['_export_date'] = date('Y-m-d H:i:s');

        // OpenID Settings
        if (!empty($options['openid_settings'])) {
            $export['authorize_endpoint'] = isset($settings['authorize_endpoint']) ? $settings['authorize_endpoint'] : '';
            $export['token_endpoint'] = isset($settings['token_endpoint']) ? $settings['token_endpoint'] : '';
            $export['userinfo_endpoint'] = isset($settings['userinfo_endpoint']) ? $settings['userinfo_endpoint'] : '';
            $export['groupinfo_endpoint'] = isset($settings['groupinfo_endpoint']) ? $settings['groupinfo_endpoint'] : '';
            $export['scope'] = isset($settings['scope']) ? $settings['scope'] : '';
            $export['redirect_uri'] = isset($settings['redirect_uri']) ? $settings['redirect_uri'] : '';
        }

        // Credentials (kun hvis eksplisitt valgt)
        if (!empty($options['credentials'])) {
            $export['client_id'] = isset($settings['client_id']) ? $settings['client_id'] : '';
            $export['client_secret'] = isset($settings['client_secret']) ? $settings['client_secret'] : '';
        }

        // Attribute Mapping
        if (!empty($options['attribute_mapping'])) {
            $export['attribute_mapping'] = isset($settings['attribute_mapping']) ? $settings['attribute_mapping'] : array();
        }

        // Role Rules
        if (!empty($options['role_rules'])) {
            $export['role_rules'] = isset($settings['role_rules']) ? $settings['role_rules'] : array();
        }

        // User Settings
        if (!empty($options['user_settings'])) {
            $export['auto_create_users'] = isset($settings['auto_create_users']) ? $settings['auto_create_users'] : false;
            $export['allow_all_authenticated'] = isset($settings['allow_all_authenticated']) ? $settings['allow_all_authenticated'] : false;
            $export['default_role'] = isset($settings['default_role']) ? $settings['default_role'] : 'subscriber';
            $export['redirect_after_login'] = isset($settings['redirect_after_login']) ? $settings['redirect_after_login'] : '';
            $export['enable_debug_logging'] = isset($settings['enable_debug_logging']) ? $settings['enable_debug_logging'] : false;
        }

        wp_send_json_success($export);
    }

    /**
     * AJAX: Importer innstillinger
     */
    public function ajax_import_settings() {
        check_ajax_referer('feide_import_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        $import_json = isset($_POST['settings']) ? stripslashes($_POST['settings']) : '';
        $import = json_decode($import_json, true);

        if (!$import) {
            wp_send_json_error('Ugyldig JSON-data');
        }

        // Validate JSON structure and types
        if (!is_array($import)) {
            wp_send_json_error('Import må være et JSON-objekt');
        }

        // Validate array fields
        if (isset($import['attribute_mapping']) && !is_array($import['attribute_mapping'])) {
            wp_send_json_error('attribute_mapping må være et array');
        }

        if (isset($import['role_rules']) && !is_array($import['role_rules'])) {
            wp_send_json_error('role_rules må være et array');
        }

        // Validate URL fields
        $url_fields = array('redirect_uri', 'authorize_endpoint', 'token_endpoint', 'userinfo_endpoint', 'groupinfo_endpoint');
        foreach ($url_fields as $field) {
            if (isset($import[$field]) && !empty($import[$field])) {
                if (!filter_var($import[$field], FILTER_VALIDATE_URL)) {
                    wp_send_json_error($field . ' må være en gyldig URL');
                }
                // Ensure HTTPS for security (OAuth requires it)
                if (strpos($import[$field], 'https://') !== 0) {
                    wp_send_json_error($field . ' må bruke HTTPS');
                }
            }
        }

        // Validate role names exist in WordPress
        if (isset($import['default_role']) && !empty($import['default_role'])) {
            if (!get_role($import['default_role'])) {
                wp_send_json_error('Ugyldig default_role: ' . esc_html($import['default_role']) . ' finnes ikke i WordPress');
            }
        }

        // Validate role rules structure
        if (isset($import['role_rules']) && is_array($import['role_rules'])) {
            foreach ($import['role_rules'] as $index => $rule) {
                if (!is_array($rule)) {
                    wp_send_json_error('Rolle-regel #' . ($index + 1) . ' må være et objekt');
                }
                if (isset($rule['role']) && !get_role($rule['role'])) {
                    wp_send_json_error('Rolle-regel #' . ($index + 1) . ': rolle "' . esc_html($rule['role']) . '" finnes ikke i WordPress');
                }
                if (isset($rule['criteria']) && !is_array($rule['criteria'])) {
                    wp_send_json_error('Rolle-regel #' . ($index + 1) . ': criteria må være et array');
                }
            }
        }

        // Validate boolean fields
        $boolean_fields = array('auto_create_users', 'allow_all_authenticated', 'enable_debug_logging');
        foreach ($boolean_fields as $field) {
            if (isset($import[$field]) && !is_bool($import[$field]) && $import[$field] !== '1' && $import[$field] !== '0') {
                wp_send_json_error($field . ' må være true eller false');
            }
        }

        // Opprett backup av nåværende innstillinger
        $current_settings = get_option('feide_wp_auth_settings', array());
        update_option('feide_wp_auth_settings_backup', $current_settings);
        update_option('feide_wp_auth_settings_backup_time', time());

        // Hent eksisterende innstillinger
        $settings = get_option('feide_wp_auth_settings', array());

        // Merge med importerte innstillinger
        $fields_to_import = array(
            'client_id', 'client_secret', 'redirect_uri', 'scope',
            'authorize_endpoint', 'token_endpoint', 'userinfo_endpoint', 'groupinfo_endpoint',
            'auto_create_users', 'allow_all_authenticated', 'default_role',
            'attribute_mapping', 'role_rules', 'redirect_after_login', 'enable_debug_logging'
        );

        foreach ($fields_to_import as $field) {
            if (isset($import[$field])) {
                $settings[$field] = $import[$field];
            }
        }

        // Lagre oppdaterte innstillinger
        update_option('feide_wp_auth_settings', $settings);

        if (WP_DEBUG) {
            error_log('FEIDE Auth: Settings imported successfully');
        }

        wp_send_json_success('Innstillinger importert');
    }

    /**
     * AJAX: Erstatt URL-er
     */
    public function ajax_replace_urls() {
        check_ajax_referer('feide_import_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        $find = isset($_POST['find']) ? $_POST['find'] : '';
        $replace = isset($_POST['replace']) ? $_POST['replace'] : '';

        if (empty($find) || empty($replace)) {
            wp_send_json_error('Vennligst fyll ut begge feltene');
        }

        $settings = get_option('feide_wp_auth_settings', array());
        $replaced_count = 0;

        // Felt som kan inneholde URL-er
        $url_fields = array(
            'redirect_uri', 'authorize_endpoint', 'token_endpoint',
            'userinfo_endpoint', 'groupinfo_endpoint', 'redirect_after_login'
        );

        foreach ($url_fields as $field) {
            if (isset($settings[$field]) && strpos($settings[$field], $find) !== false) {
                $settings[$field] = str_replace($find, $replace, $settings[$field]);
                $replaced_count++;
            }
        }

        if ($replaced_count > 0) {
            update_option('feide_wp_auth_settings', $settings);
            wp_send_json_success(array(
                'message' => "Erstattet URL-er i $replaced_count felt."
            ));
        } else {
            wp_send_json_error('Ingen URL-er ble funnet som matcher søket');
        }
    }

    /**
     * AJAX: Gjenopprett backup
     */
    public function ajax_restore_backup() {
        check_ajax_referer('feide_import_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        $backup = get_option('feide_wp_auth_settings_backup');

        if (!$backup) {
            wp_send_json_error('Ingen backup tilgjengelig');
        }

        update_option('feide_wp_auth_settings', $backup);

        if (WP_DEBUG) {
            error_log('FEIDE Auth: Settings restored from backup');
        }

        wp_send_json_success('Backup gjenopprettet');
    }

    /**
     * AJAX: Last ned backup
     */
    public function ajax_download_backup() {
        check_ajax_referer('feide_import_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        $backup = get_option('feide_wp_auth_settings_backup');

        if (!$backup) {
            wp_send_json_error('Ingen backup tilgjengelig');
        }

        wp_send_json_success($backup);
    }

    /**
     * AJAX: Slett backup
     */
    public function ajax_delete_backup() {
        check_ajax_referer('feide_import_export', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        delete_option('feide_wp_auth_settings_backup');
        delete_option('feide_wp_auth_settings_backup_time');

        if (WP_DEBUG) {
            error_log('FEIDE Auth: Backup deleted');
        }

        wp_send_json_success('Backup slettet');
    }

    /**
     * AJAX: Test endpoint connectivity
     *
     * Tests if an OAuth endpoint is reachable and returns status information.
     *
     * @since 2.5.0
     * @return void Outputs JSON response
     */
    public function ajax_test_endpoint() {
        check_ajax_referer('feide_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        $endpoint_url = isset($_POST['endpoint_url']) ? esc_url_raw($_POST['endpoint_url']) : '';

        if (empty($endpoint_url)) {
            wp_send_json_error('Ingen URL oppgitt');
        }

        // Validate URL format
        if (!filter_var($endpoint_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error('Ugyldig URL-format');
        }

        // Require HTTPS for OAuth endpoints
        if (strpos($endpoint_url, 'https://') !== 0) {
            wp_send_json_error('URL må bruke HTTPS');
        }

        // Test connectivity with a HEAD request first (lighter)
        $response = wp_remote_head($endpoint_url, array(
            'timeout' => 10,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Kunne ikke nå endpoint: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // Interpret status codes
        $status_text = '';
        $is_ok = false;

        if ($status_code >= 200 && $status_code < 300) {
            $status_text = 'OK';
            $is_ok = true;
        } elseif ($status_code == 401 || $status_code == 403) {
            $status_text = 'Tilgjengelig (krever autentisering)';
            $is_ok = true; // Expected for OAuth endpoints
        } elseif ($status_code == 404) {
            $status_text = 'Ikke funnet';
        } elseif ($status_code == 405) {
            // Method not allowed - try GET instead
            $response = wp_remote_get($endpoint_url, array(
                'timeout' => 10,
                'sslverify' => true
            ));
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code >= 200 && $status_code < 500) {
                    $status_text = 'OK';
                    $is_ok = true;
                }
            }
        } elseif ($status_code >= 500) {
            $status_text = 'Server-feil';
        } else {
            $status_text = 'Uventet status';
        }

        wp_send_json_success(array(
            'reachable' => $is_ok,
            'status_code' => $status_code,
            'status_text' => $status_text
        ));
    }
}
