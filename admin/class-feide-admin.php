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
        add_action('admin_init', array($this, 'handle_test_callback'));
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
        $sanitized = array();

        // OpenID Connect innstillinger
        $sanitized['client_id'] = isset($input['client_id']) ? sanitize_text_field($input['client_id']) : '';
        $sanitized['client_secret'] = isset($input['client_secret']) ? sanitize_text_field($input['client_secret']) : '';
        $sanitized['redirect_uri'] = isset($input['redirect_uri']) ? esc_url_raw($input['redirect_uri']) : '';
        $sanitized['scope'] = isset($input['scope']) ? sanitize_text_field($input['scope']) : '';
        $sanitized['authorize_endpoint'] = isset($input['authorize_endpoint']) ? esc_url_raw($input['authorize_endpoint']) : '';
        $sanitized['token_endpoint'] = isset($input['token_endpoint']) ? esc_url_raw($input['token_endpoint']) : '';
        $sanitized['userinfo_endpoint'] = isset($input['userinfo_endpoint']) ? esc_url_raw($input['userinfo_endpoint']) : '';
        $sanitized['groupinfo_endpoint'] = isset($input['groupinfo_endpoint']) ? esc_url_raw($input['groupinfo_endpoint']) : '';

        // Auto-oppretting av brukere
        $sanitized['auto_create_users'] = isset($input['auto_create_users']) ? true : false;

        // Attributt-mapping
        if (isset($input['attribute_mapping']) && is_array($input['attribute_mapping'])) {
            $sanitized['attribute_mapping'] = array();
            foreach ($input['attribute_mapping'] as $key => $value) {
                $sanitized['attribute_mapping'][sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        // Rolle-mappinger
        if (isset($input['role_mappings']) && is_array($input['role_mappings'])) {
            $sanitized['role_mappings'] = array();
            foreach ($input['role_mappings'] as $mapping) {
                if (isset($mapping['role']) && isset($mapping['criteria'])) {
                    $clean_mapping = array(
                        'role' => sanitize_text_field($mapping['role']),
                        'operator' => isset($mapping['operator']) ? sanitize_text_field($mapping['operator']) : 'AND',
                        'criteria' => array()
                    );

                    foreach ($mapping['criteria'] as $criterion) {
                        $clean_mapping['criteria'][] = array(
                            'attribute' => sanitize_text_field($criterion['attribute']),
                            'comparison' => sanitize_text_field($criterion['comparison']),
                            'value' => sanitize_text_field($criterion['value'])
                        );
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
     * Håndter test-callback
     */
    public function handle_test_callback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'feide-wp-auth') {
            return;
        }

        if (!isset($_GET['test-callback']) || !isset($_GET['code'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Ingen tilgang');
        }

        // Verifiser state
        if (!isset($_GET['state']) || !get_transient('feide_auth_state_' . $_GET['state'])) {
            wp_die('Ugyldig state-parameter.');
        }

        delete_transient('feide_auth_state_' . $_GET['state']);

        $settings = get_option('feide_wp_auth_settings', array());
        $code = sanitize_text_field($_GET['code']);

        // Bytt kode mot token
        $token_response = wp_remote_post($settings['token_endpoint'], array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => admin_url('admin.php?page=feide-wp-auth&test-callback=1'),
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret']
            )
        ));

        if (is_wp_error($token_response)) {
            set_transient('feide_test_error', $token_response->get_error_message(), 60);
            wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test'));
            exit;
        }

        $token_data = json_decode(wp_remote_retrieve_body($token_response), true);

        if (!isset($token_data['access_token'])) {
            set_transient('feide_test_error', 'Mottok ikke access token', 60);
            wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test'));
            exit;
        }

        // Hent brukerinformasjon
        $userinfo_response = wp_remote_get($settings['userinfo_endpoint'], array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token_data['access_token']
            )
        ));

        $user_info = json_decode(wp_remote_retrieve_body($userinfo_response), true);

        // Hent gruppeinformasjon
        $group_info = array();
        if (!empty($settings['groupinfo_endpoint'])) {
            $groupinfo_response = wp_remote_get($settings['groupinfo_endpoint'], array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token_data['access_token']
                )
            ));
            $group_info = json_decode(wp_remote_retrieve_body($groupinfo_response), true);
        }

        // Kombiner all informasjon
        $all_info = array(
            'user_info' => $user_info,
            'group_info' => $group_info,
            'token_info' => array(
                'token_type' => $token_data['token_type'],
                'expires_in' => $token_data['expires_in'],
                'scope' => isset($token_data['scope']) ? $token_data['scope'] : ''
            )
        );

        set_transient('feide_test_result', $all_info, 600);

        wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test&test-success=1'));
        exit;
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
                           value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="regular-text">
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
            <div class="feide-test-results">
                <?php $this->render_attributes_table($test_result); ?>
            </div>
            <?php delete_transient('feide_test_result'); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Generer test-autentiserings-URL
     */
    private function get_test_auth_url($settings) {
        $state = wp_create_nonce('feide_test_state');
        set_transient('feide_auth_state_' . $state, true, 600);

        $params = array(
            'client_id' => $settings['client_id'],
            'redirect_uri' => admin_url('admin.php?page=feide-wp-auth&test-callback=1'),
            'response_type' => 'code',
            'scope' => $settings['scope'],
            'state' => $state
        );

        return $settings['authorize_endpoint'] . '?' . http_build_query($params);
    }

    /**
     * Render attributter som tabell
     */
    private function render_attributes_table($data, $prefix = '') {
        if (!is_array($data)) {
            echo '<p>' . esc_html($data) . '</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Attributt</th><th>Verdi</th></tr></thead>';
        echo '<tbody>';

        foreach ($data as $key => $value) {
            $full_key = $prefix ? $prefix . ':' . $key : $key;

            if (is_array($value)) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($full_key) . '</strong></td>';
                echo '<td>';
                $this->render_attributes_table($value, $full_key);
                echo '</td>';
                echo '</tr>';
            } else {
                echo '<tr>';
                echo '<td><code>' . esc_html($full_key) . '</code></td>';
                echo '<td>' . esc_html($value) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
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
                <h3>Rolleregel #<?php echo $index + 1; ?>
                    <button type="button" class="button remove-role-mapping">Fjern</button>
                </h3>

                <table class="form-table">
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
                               placeholder="Attributt (f.eks. eduPersonOrgUnitDN:norEduOrgUnitUniqueIdentifier)"
                               value="<?php echo esc_attr($criterion['attribute']); ?>"
                               class="regular-text">

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

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var mappingIndex = <?php echo count($role_mappings); ?>;

            // Legg til ny rolleregel
            $('#add-role-mapping').on('click', function() {
                var newMapping = `
                    <div class="role-mapping-item" data-index="${mappingIndex}">
                        <h3>Rolleregel #${mappingIndex + 1}
                            <button type="button" class="button remove-role-mapping">Fjern</button>
                        </h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">WordPress-rolle</th>
                                <td>
                                    <select name="feide_wp_auth_settings[role_mappings][${mappingIndex}][role]" class="regular-text">
                                        <?php foreach ($wp_roles as $role_key => $role_name): ?>
                                            <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Kriterier-operator</th>
                                <td>
                                    <label>
                                        <input type="radio" name="feide_wp_auth_settings[role_mappings][${mappingIndex}][operator]" value="AND" checked>
                                        AND (alle kriterier må være oppfylt)
                                    </label>
                                    <br>
                                    <label>
                                        <input type="radio" name="feide_wp_auth_settings[role_mappings][${mappingIndex}][operator]" value="OR">
                                        OR (minst ett kriterium må være oppfylt)
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <h4>Kriterier</h4>
                        <div class="criteria-container" data-mapping-index="${mappingIndex}">
                            <div class="criterion-item">
                                <input type="text" name="feide_wp_auth_settings[role_mappings][${mappingIndex}][criteria][0][attribute]"
                                       placeholder="Attributt" class="regular-text">
                                <select name="feide_wp_auth_settings[role_mappings][${mappingIndex}][criteria][0][comparison]">
                                    <option value="equals">Er lik</option>
                                    <option value="contains">Inneholder</option>
                                    <option value="starts_with">Starter med</option>
                                    <option value="ends_with">Slutter med</option>
                                    <option value="not_equals">Er ikke lik</option>
                                </select>
                                <input type="text" name="feide_wp_auth_settings[role_mappings][${mappingIndex}][criteria][0][value]"
                                       placeholder="Verdi" class="regular-text">
                                <button type="button" class="button remove-criterion">Fjern</button>
                            </div>
                        </div>
                        <p>
                            <button type="button" class="button add-criterion" data-mapping-index="${mappingIndex}">Legg til kriterium</button>
                        </p>
                    </div>
                `;
                $('#role-mappings-container').append(newMapping);
                mappingIndex++;
            });

            // Fjern rolleregel
            $(document).on('click', '.remove-role-mapping', function() {
                $(this).closest('.role-mapping-item').remove();
            });

            // Legg til kriterium
            $(document).on('click', '.add-criterion', function() {
                var mappingIdx = $(this).data('mapping-index');
                var container = $(this).closest('.role-mapping-item').find('.criteria-container');
                var criterionCount = container.find('.criterion-item').length;

                var newCriterion = `
                    <div class="criterion-item">
                        <input type="text" name="feide_wp_auth_settings[role_mappings][${mappingIdx}][criteria][${criterionCount}][attribute]"
                               placeholder="Attributt" class="regular-text">
                        <select name="feide_wp_auth_settings[role_mappings][${mappingIdx}][criteria][${criterionCount}][comparison]">
                            <option value="equals">Er lik</option>
                            <option value="contains">Inneholder</option>
                            <option value="starts_with">Starter med</option>
                            <option value="ends_with">Slutter med</option>
                            <option value="not_equals">Er ikke lik</option>
                        </select>
                        <input type="text" name="feide_wp_auth_settings[role_mappings][${mappingIdx}][criteria][${criterionCount}][value]"
                               placeholder="Verdi" class="regular-text">
                        <button type="button" class="button remove-criterion">Fjern</button>
                    </div>
                `;
                container.append(newCriterion);
            });

            // Fjern kriterium
            $(document).on('click', '.remove-criterion', function() {
                var container = $(this).closest('.criteria-container');
                if (container.find('.criterion-item').length > 1) {
                    $(this).closest('.criterion-item').remove();
                } else {
                    alert('Du må ha minst ett kriterium.');
                }
            });
        });
        </script>
        <?php
    }
}
