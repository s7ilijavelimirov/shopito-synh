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
        add_action('wp_ajax_clear_shopito_logs', array($this, 'clear_logs'));
    }

    public function clear_logs()
    {
        check_ajax_referer('shopito_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nemate dozvolu za ovu akciju');
            return;
        }

        $logger = Logger::get_instance();
        $logger->clear_logs();

        wp_send_json_success('Logovi su uspešno obrisani');
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

        $logger = Logger::get_instance();
        $logger->info("Testing connection", ['type' => $test_type]);

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

            $logger->info("Testing REST API connection", ['endpoint' => $endpoint]);

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

            $logger->info("Testing Basic Auth connection", ['endpoint' => $endpoint]);

            $response = wp_remote_get($endpoint, $args);
        }

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $debug_info['error'] = $error_msg;
            $logger->error("Connection test failed: " . $error_msg);

            wp_send_json_error([
                'message' => "Greška pri konekciji: " . $error_msg,
                'debug_info' => $debug_info
            ]);
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        $debug_info['response_code'] = $response_code;
        $debug_info['response_body'] = json_decode($response_body);

        $logger->info("Connection test response", [
            'response_code' => $response_code,
            'response_body_length' => strlen($response_body)
        ]);

        if ($response_code === 200) {
            $success_message = $test_type === 'rest' ?
                "REST API konekcija uspešna (Consumer Keys)!" :
                "User Auth konekcija uspešna!";

            $logger->success($success_message);

            wp_send_json_success([
                'message' => $success_message,
                'debug_info' => $debug_info
            ]);
        } else {
            $error_message = $test_type === 'rest' ?
                "REST API konekcija neuspešna." :
                "User Auth konekcija neuspešna.";

            $logger->error($error_message, ['status' => $response_code]);

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

        // Dodajemo podstranicu za logove
        add_submenu_page(
            'shopito-sync',
            'Shopito Sync Logs',
            'Logovi',
            'manage_options',
            'shopito-sync-logs',
            array($this, 'render_logs_page')
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

        // Dodajemo novu sekciju za podešavanja logovanja
        add_settings_section(
            'shopito_sync_logging',
            'Podešavanja logovanja',
            array($this, 'render_logging_section'),
            'shopito-sync'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields()
    {
        $fields = [
            'target_url' => [
                'label' => 'URL sajta (shopito.ba)',
                'render_callback' => 'render_target_url_field',
                'section' => 'shopito_sync_main'
            ],
            'consumer_key' => [
                'label' => 'WooCommerce Consumer Key',
                'render_callback' => 'render_consumer_key_field',
                'section' => 'shopito_sync_main'
            ],
            'consumer_secret' => [
                'label' => 'WooCommerce Consumer Secret',
                'render_callback' => 'render_consumer_secret_field',
                'section' => 'shopito_sync_main'
            ],
            'username' => [
                'label' => 'Username',
                'render_callback' => 'render_username_field',
                'section' => 'shopito_sync_main'
            ],
            'password' => [
                'label' => 'Application Password',
                'render_callback' => 'render_password_field',
                'section' => 'shopito_sync_main'
            ],
            'enable_logging' => [
                'label' => 'Omogući logovanje',
                'render_callback' => 'render_enable_logging_field',
                'section' => 'shopito_sync_logging'
            ]
        ];

        foreach ($fields as $field_id => $field) {
            add_settings_field(
                $field_id,
                $field['label'],
                array($this, $field['render_callback']),
                'shopito-sync',
                $field['section']
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

        // Validacija polja za logovanje
        $validated['enable_logging'] = isset($input['enable_logging']) ? 'yes' : 'no';

        // Ažuriramo status logovanja u logger instanci
        $logger = Logger::get_instance();
        $logger->set_enabled($validated['enable_logging'] === 'yes');

        return $validated;
    }

    public function render_logging_section($args)
    {
?>
        <p>Ovde možete podesiti opcije za logovanje operacija sinhronizacije.</p>
    <?php
    }

    public function render_enable_logging_field()
    {
        $options = get_option('shopito_sync_settings');
        $checked = isset($options['enable_logging']) && $options['enable_logging'] === 'yes';
    ?>
        <label>
            <input type="checkbox"
                name="shopito_sync_settings[enable_logging]"
                value="yes"
                <?php checked($checked); ?>>
            Omogući logovanje operacija sinhronizacije
        </label>
        <p class="description">Kada je uključeno, plugin će beležiti detaljne informacije o svakoj sinhronizaciji.</p>
    <?php
    }

    public function render_logs_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $logger = Logger::get_instance();
        $logs = $logger->get_logs();
        $is_enabled = $logger->is_enabled();
    ?>
        <div class="wrap">
            <h1><?php echo esc_html('Shopito Sync - Logovi'); ?></h1>

            <div class="notice notice-info">
                <p><?php echo $is_enabled ? 'Logovanje je trenutno <strong>uključeno</strong>.' : 'Logovanje je trenutno <strong>isključeno</strong>.'; ?>
                    Možete promeniti ovo podešavanje u <a href="<?php echo admin_url('admin.php?page=shopito-sync'); ?>">glavnim podešavanjima</a>.</p>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <button type="button" id="clear-logs" class="button" data-nonce="<?php echo wp_create_nonce('shopito_sync_nonce'); ?>">
                        Obriši sve logove
                    </button>
                </div>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo count($logs); ?> stavki</span>
                </div>
                <br class="clear">
            </div>

            <table class="wp-list-table widefat fixed striped logs-table">
                <thead>
                    <tr>
                        <th scope="col" class="column-date">Vreme</th>
                        <th scope="col" class="column-level">Nivo</th>
                        <th scope="col" class="column-message">Poruka</th>
                        <th scope="col" class="column-context">Kontekst</th>
                    </tr>
                </thead>
                <tbody id="the-list">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="4">Nema dostupnih logova.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($log['timestamp']))); ?></td>
                                <td>
                                    <span class="log-level log-level-<?php echo esc_attr($log['level']); ?>">
                                        <?php echo esc_html(ucfirst($log['level'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td>
                                    <?php if (!empty($log['context'])): ?>
                                        <pre><?php echo esc_html(json_encode($log['context'], JSON_PRETTY_PRINT)); ?></pre>
                                    <?php else: ?>
                                        <em>Nema konteksta</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .log-level {
                display: inline-block;
                padding: 3px 6px;
                border-radius: 3px;
                font-weight: bold;
                color: white;
            }

            .log-level-info {
                background-color: #0073aa;
            }

            .log-level-error {
                background-color: #dc3232;
            }

            .log-level-success {
                background-color: #46b450;
            }

            .log-level-warning {
                background-color: #ffb900;
                color: #444;
            }

            .logs-table .column-date {
                width: 15%;
            }

            .logs-table .column-level {
                width: 10%;
            }

            .logs-table .column-message {
                width: 40%;
            }

            .logs-table .column-context {
                width: 35%;
            }

            .logs-table pre {
                margin: 0;
                padding: 5px;
                background: #f8f8f8;
                overflow: auto;
                max-height: 100px;
                font-size: 11px;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                $('#clear-logs').on('click', function() {
                    if (confirm('Da li ste sigurni da želite da obrišete sve logove?')) {
                        var nonce = $(this).data('nonce');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'clear_shopito_logs',
                                nonce: nonce
                            },
                            beforeSend: function() {
                                $(this).prop('disabled', true);
                            },
                            success: function(response) {
                                if (response.success) {
                                    location.reload();
                                } else {
                                    alert('Greška: ' + response.data);
                                }
                            },
                            error: function() {
                                alert('Došlo je do greške prilikom brisanja logova.');
                            },
                            complete: function() {
                                $(this).prop('disabled', false);
                            }
                        });
                    }
                });
            });
        </script>
    <?php
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

            <?php if (Logger::get_instance()->is_enabled()): ?>
                <div class="recent-logs-section" style="margin-top: 30px;">
                    <h2>Skorašnji logovi</h2>
                    <p>Prikazani su 10 najnovijih logova. <a href="<?php echo admin_url('admin.php?page=shopito-sync-logs'); ?>">Pogledaj sve logove</a></p>

                    <table class="wp-list-table widefat fixed striped logs-table">
                        <thead>
                            <tr>
                                <th scope="col" class="column-date">Vreme</th>
                                <th scope="col" class="column-level">Nivo</th>
                                <th scope="col" class="column-message">Poruka</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $recent_logs = Logger::get_instance()->get_logs(10);
                            if (empty($recent_logs)):
                            ?>
                                <tr>
                                    <td colspan="3">Nema dostupnih logova.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_logs as $log): ?>
                                    <tr>
                                        <td><?php echo esc_html(date_i18n('d.m.Y H:i:s', strtotime($log['timestamp']))); ?></td>
                                        <td>
                                            <span class="log-level log-level-<?php echo esc_attr($log['level']); ?>">
                                                <?php echo esc_html(ucfirst($log['level'])); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($log['message']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .log-level {
                display: inline-block;
                padding: 3px 6px;
                border-radius: 3px;
                font-weight: bold;
                color: white;
            }

            .log-level-info {
                background-color: #0073aa;
            }

            .log-level-error {
                background-color: #dc3232;
            }

            .log-level-success {
                background-color: #46b450;
            }

            .log-level-warning {
                background-color: #ffb900;
                color: #444;
            }
        </style>
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
