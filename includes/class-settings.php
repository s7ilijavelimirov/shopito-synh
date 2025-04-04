<?php

namespace Shopito_Sync;

class Settings
{
    private $options;

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('wp_ajax_test_shopito_connection', array($this, 'test_shopito_connection'));
    }
    private function get_environment_settings($url)
    {
        // Lokalno okruženje
        if (
            strpos($url, 'localhost') !== false ||
            strpos($url, '.local') !== false ||
            strpos($url, '.test') !== false
        ) {
            return [
                'environment' => 'local',
                'force_ssl' => false,
                'verify_ssl' => false
            ];
        }

        // Provera da li URL koristi HTTPS
        $is_ssl = strpos($url, 'https://') === 0;

        // Za sve ostale slučajeve, koristimo HTTPS ako je dostupan
        return [
            'environment' => $is_ssl ? 'production' : 'development',
            'force_ssl' => true, // Uvek pokušavamo sa HTTPS za non-local
            'verify_ssl' => $is_ssl // Verifikujemo SSL samo ako već koristi HTTPS
        ];
    }
    public function test_shopito_connection()
    {
        check_ajax_referer('test_shopito_connection', 'nonce');

        $settings = get_option('shopito_sync_settings');
        $test_type = isset($_POST['test_type']) ? sanitize_text_field($_POST['test_type']) : 'rest';
        $debug_info = [];

        if ($test_type === 'rest') {
            // Test REST API sa Consumer Keys
            $endpoint = trailingslashit($settings['target_url']) . 'wp-json/wc/v3/products';
            $args = [
                'timeout' => 30,
                'sslverify' => false,
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ];

            // Dodajemo consumer key parametre u URL
            $endpoint = add_query_arg([
                'consumer_key' => $settings['consumer_key'],
                'consumer_secret' => $settings['consumer_secret'],
                'per_page' => 1
            ], $endpoint);

            $debug_info['endpoint'] = $endpoint;
            $debug_info['auth_type'] = 'REST API (Consumer Keys)';

            $response = wp_remote_get($endpoint, $args);
        } else {
            // Test WordPress User Auth
            $endpoint = trailingslashit($settings['target_url']) . 'wp-json/wp/v2/media';
            $args = [
                'timeout' => 30,
                'sslverify' => false,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($settings['username'] . ':' . $settings['password']),
                    'Accept' => 'application/json'
                ]
            ];

            $debug_info['endpoint'] = $endpoint;
            $debug_info['auth_type'] = 'Basic Auth (Application Password)';

            $response = wp_remote_get($endpoint, $args);
        }

        if (is_wp_error($response)) {
            $debug_info['error'] = $response->get_error_message();
            wp_send_json_error([
                'message' => "Greška pri konekciji: " . $response->get_error_message(),
                'debug_info' => $debug_info
            ]);
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $debug_info['response_code'] = $response_code;
        $debug_info['response_body'] = json_decode($response_body);

        if ($response_code === 200) {
            $success_message = $test_type === 'rest' ?
                "REST API konekcija uspešna (Consumer Keys)!" :
                "User Auth konekcija uspešna!";
            wp_send_json_success([
                'message' => $success_message,
                'debug_info' => $debug_info
            ]);
        } else {
            $error_message = $test_type === 'rest' ?
                "REST API konekcija neuspešna." :
                "User Auth konekcija neuspešna.";
            wp_send_json_error([
                'message' => $error_message . " Status: " . $response_code,
                'debug_info' => $debug_info
            ]);
        }
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'Shopito Sync',
            'Shopito Sync',
            'manage_options',
            'shopito-sync',
            array($this, 'render_settings_page'),
            'dashicons-rest-api'
        );
    }

    public function init_settings()
    {
        register_setting(
            'shopito_sync_options',
            'shopito_sync_settings',
            array($this, 'validate_settings')
        );

        add_settings_section(
            'shopito_sync_main',
            'API Podešavanja',
            array($this, 'render_section'),
            'shopito-sync'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields()
    {
        $fields = [
            'target_url' => [
                'label' => 'URL sajta (shopito.ba)',
                'render_callback' => 'render_target_url_field'
            ],
            'consumer_key' => [
                'label' => 'WooCommerce Consumer Key',
                'render_callback' => 'render_consumer_key_field'
            ],
            'consumer_secret' => [
                'label' => 'WooCommerce Consumer Secret',
                'render_callback' => 'render_consumer_secret_field'
            ],
            'username' => [
                'label' => 'Username',
                'render_callback' => 'render_username_field'
            ],
            'password' => [
                'label' => 'Application Password',
                'render_callback' => 'render_password_field'
            ]
        ];

        foreach ($fields as $field_id => $field) {
            add_settings_field(
                $field_id,
                $field['label'],
                array($this, $field['render_callback']),
                'shopito-sync',
                'shopito_sync_main'
            );
        }
    }

    public function validate_settings($input)
    {
        $validated = array();

        if (!empty($input['target_url'])) {
            $url = esc_url_raw(trailingslashit($input['target_url']));
            $env_settings = $this->get_environment_settings($url);

            if ($env_settings['force_ssl']) {
                $validated['target_url'] = str_replace('http://', 'https://', $url);
            } else {
                $validated['target_url'] = $url;
            }

            $validated['environment'] = $env_settings['environment'];
            $validated['verify_ssl'] = $env_settings['verify_ssl'];
        }

        // Validacija ostalih polja
        $required_fields = [
            'consumer_key' => 'Consumer Key',
            'consumer_secret' => 'Consumer Secret',
            'username' => 'Username',
            'password' => 'Application Password'
        ];

        foreach ($required_fields as $field => $label) {
            if (!empty($input[$field])) {
                $validated[$field] = sanitize_text_field($input[$field]);
            } else {
                add_settings_error(
                    'shopito_sync_settings',
                    'missing_' . $field,
                    $label . ' je obavezan'
                );
            }
        }

        return $validated;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('shopito_sync_settings');
        $environment = isset($settings['environment']) ? $settings['environment'] : 'test';

        settings_errors('shopito_sync_settings');
?>
        <div class="wrap">
            <h1>
                <?php echo esc_html(get_admin_page_title()); ?>
                <span class="environment-label <?php echo esc_attr($environment); ?>">
                    <?php echo esc_html(ucfirst($environment)); ?>
                </span>
            </h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('shopito_sync_options');
                do_settings_sections('shopito-sync');
                submit_button('Sačuvaj podešavanja');
                ?>
            </form>
            <div class="test-connection-section" style="margin-top: 20px;">
                <button type="button" class="button button-primary" id="test-rest-api">
                    Testiraj REST API konekciju
                </button>
                <button type="button" class="button button-secondary" id="test-user-auth">
                    Testiraj User Auth konekciju
                </button>
                <span class="spinner" style="float:none;"></span>
                <div id="connection-result"></div>
            </div>
        </div>
    <?php
    }

    // Field rendering methods...
    public function render_section($args)
    {
    ?>
        <p>Unesite podatke za povezivanje sa shopito.ba sajtom. Za produkciju koristite HTTPS, a za test okruženje možete koristiti HTTP.</p>
    <?php
    }

    public function render_target_url_field()
    {
        $options = get_option('shopito_sync_settings');
        $value = isset($options['target_url']) ? esc_url($options['target_url']) : '';
    ?>
        <input type="url"
            name="shopito_sync_settings[target_url]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text">
        <p class="description">Unesite kompletan URL sajta, npr. https://shopito.ba</p>
    <?php
    }
    public function render_username_field()
    {
        $options = get_option('shopito_sync_settings');
        $value = isset($options['username']) ? esc_attr($options['username']) : '';
    ?>
        <input type="text"
            name="shopito_sync_settings[username]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text">
    <?php
    }

    public function render_password_field()
    {
        $options = get_option('shopito_sync_settings');
        $value = isset($options['password']) ? esc_attr($options['password']) : '';
    ?>
        <input type="password"
            name="shopito_sync_settings[password]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text">
    <?php
    }
    public function render_consumer_key_field()
    {
        $options = get_option('shopito_sync_settings');
        $value = isset($options['consumer_key']) ? esc_attr($options['consumer_key']) : '';
    ?>
        <input type="text"
            name="shopito_sync_settings[consumer_key]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text">
    <?php
    }

    public function render_consumer_secret_field()
    {
        $options = get_option('shopito_sync_settings');
        $value = isset($options['consumer_secret']) ? esc_attr($options['consumer_secret']) : '';
    ?>
        <input type="text"
            name="shopito_sync_settings[consumer_secret]"
            value="<?php echo esc_attr($value); ?>"
            class="regular-text">
<?php
    }
}
