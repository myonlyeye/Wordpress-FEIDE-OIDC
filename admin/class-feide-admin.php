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
            'nonce' => wp_create_nonce('feide_test_auth')
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
                    <label for="client_id">Client ID</label>
                </th>
                <td>
                    <input type="text" id="client_id" name="feide_wp_auth_settings[client_id]"
                           value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="regular-text">
                    <p class="description">Client ID fra FEIDE</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="client_secret">Client Secret</label>
                </th>
                <td>
                    <input type="password" id="client_secret" name="feide_wp_auth_settings[client_secret]"
                           value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text" autocomplete="off">
                    <p class="description">Client Secret fra FEIDE</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="redirect_uri">Redirect / Callback URL</label>
                </th>
                <td>
                    <input type="text" id="redirect_uri" name="feide_wp_auth_settings[redirect_uri]"
                           value="<?php echo esc_attr($settings['redirect_uri'] ?? site_url('/wp-login.php?feide-auth=callback')); ?>" class="regular-text">
                    <p class="description">Denne URL-en må være registrert hos FEIDE</p>
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
        $state = wp_generate_password(32, false);
        set_transient('feide_auth_state_' . $state, true, 600);
        set_transient('feide_test_mode_' . $state, true, 600);

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

                <h4>Kriterier</h4>
                <div class="criteria-container" data-mapping-index="<?php echo $index; ?>">
                    <?php
                    $criteria = $mapping['criteria'] ?? array(array('attribute' => '', 'comparison' => 'equals', 'value' => ''));
                    foreach ($criteria as $crit_index => $criterion):
                    ?>
                    <div class="criterion-item">
                        <input type="text"
                               name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][criteria][<?php echo $crit_index; ?>][attribute]"
                               placeholder="Attributt (f.eks. groups:*:id eller user:email)"
                               value="<?php echo esc_attr($criterion['attribute']); ?>"
                               class="regular-text"
                               title="Bruk * som wildcard for å matche alle elementer i et array. Eksempel: groups:*:id matcher id fra alle grupper">

                        <select name="feide_wp_auth_settings[role_mappings][<?php echo $index; ?>][criteria][<?php echo $crit_index; ?>][comparison]">
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
                               class="regular-text">

                        <button type="button" class="button remove-criterion">Fjern</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="button" class="button add-criterion" data-mapping-index="<?php echo $index; ?>">
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
}
