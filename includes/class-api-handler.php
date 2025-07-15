<?php

namespace Shopito_Sync;

class API_Handler
{
    private $attribute_handler;
    private $settings;
    private $exchange_rate = 58.5;
    private $variation_handler;
    private $image_handler;
    private $max_retries = 3; // Maksimalan broj pokušaja za API pozive
    private $retry_delay = 2; // Delay između pokušaja u sekundama
    private $logger;

    public function __construct()
    {
        $this->settings = get_option('shopito_sync_settings');
        $this->attribute_handler = new Attribute_Handler($this->settings);
        $this->image_handler = new Image_Handler($this->settings);

        // Inicijalizujemo logger
        $this->logger = Logger::get_instance();

        // Variation_Handler inicijalizujemo ovde da bismo izbegli cirkularnu referencu
        //$this->variation_handler = new Variation_Handler($this->settings, $this);
    }
    // Setter za variation_handler, poziva se nakon inicijalizacije
    public function set_variation_handler($variation_handler)
    {
        $this->variation_handler = $variation_handler;
    }
    private function verify_image_exists($image_id)
    {
        $endpoint = trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media/' . $image_id;
        $response = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
            ],
            'sslverify' => false,
            'timeout' => 20
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    private function find_product_by_variation_skus($product)
    {
        if ($product->get_type() !== 'variable') {
            return false;
        }

        $variations = $product->get_children();
        if (empty($variations)) {
            return false;
        }

        // Skupljamo SKU-ove svih varijacija
        $variation_skus = [];
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation && !empty($variation->get_sku())) {
                $variation_skus[] = $variation->get_sku();
            }
        }

        if (empty($variation_skus)) {
            return false;
        }

        foreach ($variation_skus as $sku) {
            // Prvo probamo sa type=variation parametrom
            $endpoint = $this->build_api_endpoint("products", [
                'type' => 'variation',
                'sku' => $sku
            ]);

            $this->logger->info("Tražim proizvod preko SKU varijacije (sa type)", [
                'sku' => $sku
            ]);

            $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

            // Ako to ne uspe, probamo bez type parametra
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $variations = json_decode(wp_remote_retrieve_body($response));
                if (!empty($variations)) {
                    $parent_id = $variations[0]->parent_id;
                    $this->logger->info("Pronađen proizvod preko SKU varijacije (sa type)", [
                        'sku' => $sku,
                        'target_parent_id' => $parent_id
                    ]);
                    return $parent_id;
                }
            }

            // Probamo bez type parametra
            $endpoint = $this->build_api_endpoint("products", [
                'sku' => $sku
            ]);

            $this->logger->info("Tražim proizvod preko SKU varijacije (bez type)", [
                'sku' => $sku
            ]);

            $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $products = json_decode(wp_remote_retrieve_body($response));
                if (!empty($products)) {
                    $found_product = null;

                    // Tražimo proizod koji je varijacija
                    foreach ($products as $product) {
                        if (isset($product->type) && $product->type === 'variation' && isset($product->parent_id)) {
                            $parent_id = $product->parent_id;
                            $this->logger->info("Pronađen proizvod preko SKU varijacije (bez type)", [
                                'sku' => $sku,
                                'target_parent_id' => $parent_id
                            ]);
                            return $parent_id;
                        }
                    }
                }
            }
        }

        return false;
    }
    private function make_api_request($endpoint, $args, $method = 'GET')
    {
        $attempt = 0;
        $last_error = null;
        $success = false;

        // LiteSpeed optimizovani headers
        $default_headers = [
            'Connection' => 'keep-alive',
            'Keep-Alive' => 'timeout=300, max=100',
            'User-Agent' => 'Shopito-Sync-LiteSpeed/1.2.2',
            'Accept-Encoding' => 'gzip, deflate',
            'Cache-Control' => 'no-cache'
        ];

        if (isset($args['headers'])) {
            $args['headers'] = array_merge($default_headers, $args['headers']);
        } else {
            $args['headers'] = $default_headers;
        }

        while ($attempt < $this->max_retries && !$success) {
            if ($attempt > 0) {
                $delay = min($this->retry_delay * pow(2, $attempt - 1), 30); // Max 30s delay
                $this->logger->info("LiteSpeed retry delay: {$delay}s", [
                    'attempt' => $attempt + 1,
                    'endpoint' => $endpoint
                ]);
                sleep($delay);
            }

            // Dinamički timeout za LiteSpeed
            $timeout = $this->calculate_timeout($endpoint, $method);
            $args['timeout'] = $timeout;
            $args['httpversion'] = '1.1';
            $args['blocking'] = true;
            $args['redirection'] = 3;

            $response = wp_remote_request($endpoint, array_merge($args, ['method' => $method]));

            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);

                if ($response_code >= 200 && $response_code < 300) {
                    return $response;
                }

                // LiteSpeed specifično handling
                if ($response_code === 429 || $response_code === 503) {
                    $this->logger->warning("LiteSpeed rate limit/service unavailable, waiting longer", [
                        'response_code' => $response_code,
                        'endpoint' => $endpoint
                    ]);
                    sleep(20); // Duža pauza za LiteSpeed
                }
            }

            $last_error = is_wp_error($response) ? $response : new \WP_Error('api_error', 'API Error: ' . $response_code);
            $attempt++;
        }

        return $last_error;
    }
    private function calculate_timeout($endpoint, $method)
    {
        $base_timeout = 120; // 2 minuta osnovni timeout

        // Različiti timeout-ovi za različite operacije
        if (strpos($endpoint, 'media') !== false) {
            return 180; // 3 minuta za upload slika
        }

        if (strpos($endpoint, 'variations') !== false) {
            return 300; // 5 minuta za varijacije
        }

        if ($method === 'POST' && strpos($endpoint, 'products') !== false) {
            return 240; // 4 minuta za kreiranje proizvoda
        }

        if ($method === 'PUT') {
            return 180; // 3 minuta za ažuriranje
        }

        return $base_timeout;
    }
    public function sync_product($product_id, $skip_images = false)
    {
        // OPTIMIZOVANO za LiteSpeed server
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        // LiteSpeed specifične optimizacije
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }

        // Disable output buffering da sprečimo timeouts
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Povećavamo limits za MySQL konekciju
        if (function_exists('mysql_connect')) {
            @ini_set('mysql.connect_timeout', '300');
        }

        $steps = [];
        $logger = Logger::get_instance();

        $logger->info("Starting full product sync with LiteSpeed optimizations", [
            'product_id' => $product_id,
            'skip_images' => $skip_images,
            'memory_limit' => ini_get('memory_limit'),
            'time_limit' => ini_get('max_execution_time'),
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
        ]);

        try {
            // Periodic cleanup optimizovan za LiteSpeed
            $this->cleanup_memory_periodically();

            // Flush output early da sprečimo browser timeout
            if (!headers_sent()) {
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no'); // Nginx/LiteSpeed
            }

            // Ostatak koda ostaje isti...
            $product = $this->validate_and_prepare_product($product_id);
            if (!$product) {
                throw new \Exception("Proizvod nije pronađen");
            }

            $existing_product_id = $this->check_if_product_exists($product);

            // Optimizovano procesiranje slika za LiteSpeed
            $images = [];
            if (!$skip_images) {
                $steps[] = ['name' => 'images', 'status' => 'active', 'message' => 'Prebacivanje slika...'];

                // Koristimo manje batch-eve za LiteSpeed stabilnost
                $images = $this->process_images_with_recovery($product, $existing_product_id);

                $steps[count($steps) - 1] = [
                    'name' => 'images',
                    'status' => 'completed',
                    'message' => count($images) . ' slika(e) uploadovano'
                ];
            }

            // Pripremamo podatke
            $data = $this->prepare_product_data($product);
            if (!empty($images)) {
                $data['images'] = $images;
            }

            $endpoint = $this->build_api_endpoint($existing_product_id ? "products/{$existing_product_id}" : "products");
            $method = $existing_product_id ? 'PUT' : 'POST';

            // API poziv sa LiteSpeed optimizacijama
            $response = $this->make_api_request_with_fallback($endpoint, $data, $method);

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response));
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code !== 201 && $response_code !== 200) {
                throw new \Exception($this->get_error_message($body, $response_code));
            }

            // Ažuriramo meta podatke
            update_post_meta($product_id, '_synced_product_id', $body->id);
            update_post_meta($product_id, '_last_sync_date', current_time('mysql'));

            // Sinhronizacija varijacija
            if ($product->get_type() === 'variable') {
                $steps[] = ['name' => 'variations', 'status' => 'active', 'message' => 'Kreiranje varijacija...'];
                $variation_result = $this->sync_product_variations($product_id, $body->id, $skip_images);
                $steps[] = $variation_result;
            }

            $steps[] = [
                'name' => 'prices',
                'status' => 'completed',
                'message' => 'Cene konvertovane'
            ];

            $steps[] = [
                'name' => 'stock',
                'status' => 'completed',
                'message' => 'Stanje proizvoda ažurirano'
            ];

            $logger->success("Product sync completed successfully on LiteSpeed", [
                'product_id' => $product_id,
                'target_id' => $body->id,
                'action' => $existing_product_id ? 'updated' : 'created',
                'peak_memory' => memory_get_peak_usage(true),
                'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            ]);

            return [
                'success' => true,
                'action' => $existing_product_id ? 'updated' : 'created',
                'steps' => $steps
            ];
        } catch (\Exception $e) {
            $logger->error("Sync error on LiteSpeed: " . $e->getMessage(), [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ]);
            return new \WP_Error('sync_error', $e->getMessage());
        }
    }
    private function make_api_request_with_fallback($endpoint, $data, $method)
    {
        try {
            // Prvi pokušaj - sa slikama
            $response = $this->make_api_request($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
                ],
                'body' => json_encode($data),
                'sslverify' => false
            ], $method);

            if (!is_wp_error($response)) {
                return $response;
            }

            // Ako je problem sa slikama, pokušaj bez njih
            if (isset($data['images']) && !empty($data['images'])) {
                $this->logger->warning("Retrying without images due to error", [
                    'error' => $response->get_error_message()
                ]);

                $data_without_images = $data;
                unset($data_without_images['images']);

                $response = $this->make_api_request($endpoint, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
                    ],
                    'body' => json_encode($data_without_images),
                    'sslverify' => false
                ], $method);
            }

            return $response;
        } catch (\Exception $e) {
            return new \WP_Error('api_error', $e->getMessage());
        }
    }
    private function cleanup_memory_periodically()
    {
        static $cleanup_counter = 0;
        $cleanup_counter++;

        if ($cleanup_counter % 5 === 0) { // Češće čišćenje na LiteSpeed
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // LiteSpeed specifično čišćenje
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $this->logger->info("LiteSpeed memory cleanup performed", [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ]);
        }
    }
    private function process_images_with_recovery($product, $existing_product_id = null)
    {
        $images = [];
        $batch_size = 3; // Smanjujemo batch size za stabilnost

        $image_ids = array_filter(array_merge(
            [$product->get_image_id()],
            $product->get_gallery_image_ids()
        ));

        foreach (array_chunk($image_ids, $batch_size) as $index => $batch) {
            $this->logger->info("Processing image batch", [
                'batch' => $index + 1,
                'size' => count($batch)
            ]);

            foreach ($batch as $position => $image_id) {
                if ($image_url = wp_get_attachment_url($image_id)) {
                    try {
                        $uploaded_id = $this->image_handler->upload_image($image_url);

                        if ($uploaded_id && $this->verify_image_exists($uploaded_id)) {
                            $images[] = [
                                'id' => $uploaded_id,
                                'src' => $image_url,
                                'position' => $position
                            ];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning("Image upload failed, continuing", [
                            'image_id' => $image_id,
                            'error' => $e->getMessage()
                        ]);
                        continue;
                    }
                }
            }

            // Povećavamo pauzu između batch-eva
            if ($index < count($image_ids) / $batch_size - 1) {
                sleep(1); // 1 sekunda pauza
            }

            // Periodic cleanup
            $this->cleanup_memory_periodically();
        }

        return $images;
    }
    private function get_existing_product_images($product_id)
    {
        $endpoint = $this->build_api_endpoint("products/{$product_id}");
        $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $product_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($product_data['images'])) {
                return $product_data['images'];
            }
        }

        return [];
    }
    /**
     * Pronalazi proizvod po imenu na ciljnom sajtu
     */
    private function find_product_by_name($name)
    {
        if (empty($name)) {
            return false;
        }

        $endpoint = $this->build_api_endpoint("products", ['search' => $name]);
        $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $products = json_decode(wp_remote_retrieve_body($response));
            if (!empty($products)) {
                $remote_product = reset($products);
                return $remote_product->id;
            }
        }

        return false;
    }
    // Ostale funkcije ostaju iste
    private function process_images_in_batch($product, $existing_product_id = null)
    {
        $images = [];
        $batch_size = 5; // OPTIMIZOVANO: Povećano sa 3 na 5

        // Skupljamo sve slike (glavnu i galeriju)
        $image_ids = array_filter(array_merge(
            [$product->get_image_id()],
            $product->get_gallery_image_ids()
        ));

        // Pre-fetch sve URL-ove slika odjednom
        $image_urls = [];
        foreach ($image_ids as $image_id) {
            $url = wp_get_attachment_url($image_id);
            if ($url) {
                $image_urls[$image_id] = $url;
            }
        }

        $this->logger->info("Pre-fetched image URLs", ['count' => count($image_urls)]);

        // Procesiramo slike u batch-evima
        foreach (array_chunk($image_ids, $batch_size) as $index => $batch) {
            $this->logger->info("Processing image batch", [
                'batch' => $index + 1,
                'size' => count($batch) // DODANO: Log batch size
            ]);

            foreach ($batch as $position => $image_id) {
                if (isset($image_urls[$image_id])) {
                    $image_url = $image_urls[$image_id];
                    $uploaded_id = $this->image_handler->upload_image($image_url);

                    // Proverite da slika zaista postoji
                    if ($uploaded_id && $this->verify_image_exists($uploaded_id)) {
                        $images[] = [
                            'id' => $uploaded_id,
                            'src' => $image_url,
                            'position' => $position
                        ];
                    }
                }
            }

            // OPTIMIZOVANO: Smanjena pauza sa 0.5s na 0.2s
            if ($index < count($image_ids) / $batch_size - 1) {
                usleep(200000); // 0.2 sekunde pauza
            }
        }

        // Fallback - ako batch processing nije uspeo, pokušaj sa prepare_product_images
        if (empty($images)) {
            $this->logger->info("Batch processing failed, trying prepare_product_images");
            $images = $this->image_handler->prepare_product_images($product, $existing_product_id);
        }

        return $images;
    }
    private function validate_and_prepare_product($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->logger->error("Proizvod nije pronađen", ['product_id' => $product_id]);
            return false;
        }
        return $product;
    }

    private function prepare_product_data($product)
    {
        $data = [
            'name' => $product->get_name(),
            'regular_price' => $this->convert_price_to_bam($product->get_regular_price()),
            'sale_price' => $this->convert_price_to_bam($product->get_sale_price()),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'status' => 'draft',
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
            'tags' => $this->get_product_tags($product),
            'categories' => $this->get_product_categories($product),
            'attributes' => $this->attribute_handler->prepare_product_attributes($product),
            'meta_data' => $this->prepare_meta_data($product->get_id()),
            // Dodajemo informacije o stanju
            'stock_status' => $product->get_stock_status()
        ];

        // Dodajemo manage_stock i stock_quantity ako je manage_stock uključen
        $manage_stock = $product->get_manage_stock();
        if ($manage_stock) {
            $data['manage_stock'] = true;
            $data['stock_quantity'] = $product->get_stock_quantity();
        }

        return $data;
    }

    private function prepare_meta_data($product_id)
    {
        return array_merge(
            $this->get_yoast_meta($product_id),
            [
                [
                    'key' => '_alg_ean',
                    'value' => get_post_meta($product_id, '_alg_ean', true) ?: get_post_meta($product_id, '_ean', true)
                ]
            ]
        );
    }

    private function build_api_endpoint($path, $additional_args = [])
    {
        $args = array_merge([
            'consumer_key' => $this->settings['consumer_key'],
            'consumer_secret' => $this->settings['consumer_secret']
        ], $additional_args);

        return add_query_arg($args, trailingslashit($this->settings['target_url']) . 'wp-json/wc/v3/' . $path);
    }

    private function sync_product_variations($product_id, $target_product_id, $skip_images = false)
    {
        try {
            $variation_result = $this->variation_handler->sync_variations($product_id, $target_product_id, $skip_images);

            if ($variation_result === false) {
                return [
                    'name' => 'variations',
                    'status' => 'error',
                    'message' => 'Greška pri sinhronizaciji varijacija'
                ];
            }

            $variations_count = $this->check_variations_count($target_product_id);

            return [
                'name' => 'variations',
                'status' => 'completed',
                'message' => "Sinhronizovano {$variations_count} varijacija"
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'variations',
                'status' => 'error',
                'message' => 'Greška: ' . $e->getMessage()
            ];
        }
    }

    private function check_variations_count($product_id)
    {
        $endpoint = $this->build_api_endpoint("products/{$product_id}/variations", [
            'per_page' => 100
        ]);

        $response = wp_remote_get($endpoint, ['sslverify' => false]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return count(json_decode(wp_remote_retrieve_body($response)));
        }
        return 0;
    }
    private function get_product_categories($product)
    {
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');

        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                // Proveravamo ancestor kategorije
                $term_parents = get_ancestors($term->term_id, 'product_cat', 'taxonomy');

                // Strategija 1: Pretraga po slug-u
                $slug_endpoint = $this->build_api_endpoint("products/categories", [
                    'slug' => $term->slug
                ]);

                $slug_response = wp_remote_get($slug_endpoint, ['sslverify' => false]);

                if (!is_wp_error($slug_response) && wp_remote_retrieve_response_code($slug_response) === 200) {
                    $remote_categories = json_decode(wp_remote_retrieve_body($slug_response));

                    if (!empty($remote_categories)) {
                        $found_category = $remote_categories[0];
                        $categories[] = [
                            'id' => $found_category->id,
                            'name' => $found_category->name,
                            'slug' => $found_category->slug,
                            'parent' => $found_category->parent ?? 0
                        ];
                        continue;
                    }
                }

                // Strategija 2: Pretraga po imenu
                $name_endpoint = $this->build_api_endpoint("products/categories", [
                    'search' => $term->name
                ]);

                $name_response = wp_remote_get($name_endpoint, ['sslverify' => false]);

                if (!is_wp_error($name_response) && wp_remote_retrieve_response_code($name_response) === 200) {
                    $remote_categories = json_decode(wp_remote_retrieve_body($name_response));

                    if (!empty($remote_categories)) {
                        $found_category = $remote_categories[0];
                        $categories[] = [
                            'id' => $found_category->id,
                            'name' => $found_category->name,
                            'slug' => $found_category->slug,
                            'parent' => $found_category->parent ?? 0
                        ];
                        continue;
                    }
                }

                // Ako ne pronađemo kategoriju
                $categories[] = [
                    'name' => $term->name,
                    'slug' => $term->slug
                ];
            }
        }

        return $categories;
    }
    public function convert_price_to_bam($price)
    {
        if (empty($price) || !is_numeric($price)) {
            return $price;
        }

        $converted_price = $price / $this->exchange_rate;
        $final_price = ceil($converted_price);

        return $final_price == (int)$final_price ?
            (string)(int)$final_price :
            number_format($final_price, 2, '.', '');
    }

    private function check_if_product_exists($product)
    {
        $synced_id = get_post_meta($product->get_id(), '_synced_product_id', true);

        if ($synced_id) {
            $endpoint = $this->build_api_endpoint("products/{$synced_id}");
            $response = wp_remote_get($endpoint, ['sslverify' => false]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                return $synced_id;
            }
        }

        // Ako ima SKU, proverimo preko njega
        if ($sku = $product->get_sku()) {
            $endpoint = $this->build_api_endpoint("products", ['sku' => $sku]);
            $response = wp_remote_get($endpoint, ['sslverify' => false]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $products = json_decode(wp_remote_retrieve_body($response));
                if (!empty($products)) {
                    $remote_product = reset($products);
                    update_post_meta($product->get_id(), '_synced_product_id', $remote_product->id);
                    return $remote_product->id;
                }
            }
        }

        // DODATO: Provera preko SKU-ova varijacija ako je varijabilni proizvod
        if ($product->get_type() === 'variable') {
            $target_id = $this->find_product_by_variation_skus($product);
            if ($target_id) {
                $this->logger->info("Proizvod pronađen preko SKU varijacija", [
                    'product_id' => $product->get_id(),
                    'target_id' => $target_id
                ]);
                update_post_meta($product->get_id(), '_synced_product_id', $target_id);
                return $target_id;
            }
        }

        return false;
    }

    private function get_yoast_meta($product_id)
    {
        $meta_keys = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_yoast_wpseo_focuskw',
            '_yoast_wpseo_meta-robots-noindex',
            '_yoast_wpseo_meta-robots-nofollow',
            '_yoast_wpseo_canonical',
            '_yoast_wpseo_og_title',
            '_yoast_wpseo_og_description',
            '_yoast_wpseo_og_image',
            '_yoast_wpseo_twitter_title',
            '_yoast_wpseo_twitter_description',
            '_yoast_wpseo_twitter_image'
        ];

        $meta_data = [];
        foreach ($meta_keys as $key) {
            $value = get_post_meta($product_id, $key, true);
            if ($value) {
                $meta_data[] = [
                    'key' => $key,
                    'value' => $value
                ];
            }
        }

        return $meta_data;
    }

    private function get_product_tags($product)
    {
        $tags = [];
        $terms = get_the_terms($product->get_id(), 'product_tag');

        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $tags[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug
                ];
            }
        }

        return $tags;
    }

    private function get_error_message($body, $response_code)
    {
        $message = isset($body->message) ? $body->message : (isset($body->error) ? $body->error : 'Nepoznata greška');
        return "API greška: {$message} (Response Code: {$response_code})";
    }

    private function log_info($message)
    {
        error_log("ℹ️ Shopito Sync: {$message}");
    }

    private function log_error($message)
    {
        error_log("❌ Shopito Sync: {$message}");
    }

    private function log_success($message)
    {
        error_log("✅ Shopito Sync: {$message}");
    }
    /**
     * Dobavlja sve varijacije proizvoda sa ciljnog sajta
     * 
     * @param int $product_id ID proizvoda na ciljnom sajtu
     * @return array Varijacije
     */
    private function get_all_target_variations($product_id)
    {
        $endpoint = $this->build_api_endpoint("products/{$product_id}/variations", [
            'per_page' => 100
        ]);

        $response = wp_remote_get($endpoint, ['sslverify' => false]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return json_decode(wp_remote_retrieve_body($response));
        }
        return [];
    }
    /**
     * Unapređeni metod za sinhronizaciju stanja
     * 
     * @param int $product_id ID proizvoda koji treba sinhronizovati
     * @return array|WP_Error Rezultat sinhronizacije
     */
    public function sync_product_stock($product_id)
    {
        $steps = [];
        $this->logger->info("Starting stock sync", ['product_id' => $product_id]);

        try {
            // 1. Validacija proizvoda
            $product = $this->validate_and_prepare_product($product_id);
            if (!$product) {
                throw new \Exception('Proizvod nije pronađen');
            }

            // 2. Pronalazak proizvoda na ciljnom sajtu koristeći sve moguće metode
            $target_product_id = $this->find_target_product($product);

            if (!$target_product_id) {
                $this->logger->error("Proizvod nije pronađen na ciljnom sajtu", [
                    'product_id' => $product_id,
                    'sku' => $product->get_sku()
                ]);
                throw new \Exception('Proizvod nije pronađen na ciljnom sajtu. Prvo izvršite punu sinhronizaciju.');
            }

            $this->logger->info("Pronađen proizvod na ciljnom sajtu", [
                'product_id' => $product_id,
                'target_id' => $target_product_id
            ]);

            // 3. Pripremamo podatke samo za stanje
            $stock_data = $this->prepare_stock_data($product);

            $this->logger->info("Pripremljeni podaci za stanje", $stock_data);

            // 4. Slanje na ciljni sajt
            $endpoint = $this->build_api_endpoint("products/{$target_product_id}");
            $this->logger->info("Slanje podataka o stanju", ['endpoint' => $endpoint]);

            $response = $this->make_api_request($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode($stock_data),
                'timeout' => 60,
                'sslverify' => false
            ], 'PUT');

            if (is_wp_error($response)) {
                $this->logger->error("API request failed: " . $response->get_error_message());
                throw new \Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response));
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code !== 200) {
                $error_msg = $this->get_error_message($body, $response_code);
                $this->logger->error("API response error: " . $error_msg);
                throw new \Exception($error_msg);
            }

            // Zapamtimo ID proizvoda na ciljnom sajtu za buduće sinhronizacije
            update_post_meta($product_id, '_synced_product_id', $target_product_id);
            update_post_meta($product_id, '_last_stock_sync_date', current_time('mysql'));

            $this->logger->success("Stock data sent successfully", [
                'product_id' => $product_id,
                'target_id' => $target_product_id
            ]);

            // 5. Sinhronizacija varijacija ako je potrebno
            if ($product->get_type() === 'variable') {
                $steps[] = ['name' => 'variations', 'status' => 'active', 'message' => 'Ažuriranje stanja varijacija...'];
                $variation_result = $this->sync_variations_stock($product, $target_product_id);
                $steps[] = $variation_result;
            }

            $steps[] = [
                'name' => 'stock',
                'status' => 'completed',
                'message' => 'Stanje proizvoda sinhronizovano'
            ];

            return [
                'success' => true,
                'action' => 'stock_updated',
                'steps' => $steps
            ];
        } catch (\Exception $e) {
            $this->logger->error("Stock sync error: " . $e->getMessage());
            return new \WP_Error('sync_error', $e->getMessage());
        }
    }

    /**
     * Sinhronizuje stanje varijacija proizvoda
     * 
     * @param WC_Product $product Proizvod
     * @param int $target_product_id ID proizvoda na ciljnom sajtu
     * @return array Rezultat sinhronizacije
     */
    private function sync_variations_stock($product, $target_product_id)
    {
        try {
            // Dobavljamo postojeće varijacije na ciljnom sajtu
            $target_variations = $this->get_all_target_variations($target_product_id);
            if (empty($target_variations)) {
                return [
                    'name' => 'variations',
                    'status' => 'error',
                    'message' => 'Varijacije nisu pronađene na ciljnom sajtu'
                ];
            }

            // Dobavljamo varijacije sa izvornog sajta
            $variations = $product->get_children();
            $synced_count = 0;

            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;

                $target_variation = $this->find_matching_target_variation($variation, $target_variations);
                if (!$target_variation) continue;

                // Pripremamo podatke samo za stanje
                $stock_status = $variation->get_stock_status();
                $manage_stock = $variation->get_manage_stock();
                $stock_quantity = $variation->get_stock_quantity();

                $data = [
                    'stock_status' => $stock_status,
                ];

                if ($manage_stock) {
                    $data['manage_stock'] = true;
                    $data['stock_quantity'] = $stock_quantity;
                }

                // Ažuriramo varijaciju na ciljnom sajtu
                $endpoint = $this->build_api_endpoint("products/{$target_product_id}/variations/{$target_variation->id}");
                $response = $this->make_api_request($endpoint, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
                    ],
                    'body' => json_encode($data),
                    'timeout' => 60,
                    'sslverify' => false
                ], 'PUT');

                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $synced_count++;
                }
            }

            return [
                'name' => 'variations',
                'status' => 'completed',
                'message' => "Sinhronizovano stanje za {$synced_count} varijacija"
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'variations',
                'status' => 'error',
                'message' => 'Greška: ' . $e->getMessage()
            ];
        }
    }
    /**
     * Priprema podatke o stanju proizvoda
     * 
     * @param WC_Product $product Proizvod
     * @return array Podaci o stanju proizvoda
     */
    private function prepare_stock_data($product)
    {
        $stock_status = $product->get_stock_status();
        $manage_stock = $product->get_manage_stock();
        $stock_quantity = $product->get_stock_quantity();

        $data = [
            'stock_status' => $stock_status,
        ];

        if ($manage_stock) {
            $data['manage_stock'] = true;
            $data['stock_quantity'] = $stock_quantity;
        }

        return $data;
    }
    /**
     * Traži proizvod na ciljnom sajtu koristeći sve dostupne metode
     * 
     * @param WC_Product $product Proizvod
     * @return int|false ID proizvoda na ciljnom sajtu ili false ako nije pronađen
     */
    private function find_target_product($product)
    {
        // 1. Prvo pokušamo sa meta podatkom
        $target_id = get_post_meta($product->get_id(), '_synced_product_id', true);
        if ($target_id) {
            // Proverimo da li proizvod još uvek postoji na ciljnom sajtu
            if ($this->check_if_product_exists_by_id($target_id)) {
                $this->logger->info("Proizvod pronađen preko meta podataka", [
                    'product_id' => $product->get_id(),
                    'target_id' => $target_id
                ]);
                return $target_id;
            }
        }

        // 2. Pokušamo sa istim ID-jem
        if ($this->check_if_product_exists_by_id($product->get_id())) {
            $this->logger->info("Proizvod pronađen sa istim ID-jem", [
                'product_id' => $product->get_id(),
            ]);
            update_post_meta($product->get_id(), '_synced_product_id', $product->get_id());
            return $product->get_id();
        }

        // 3. Pokušamo sa SKU
        $sku = $product->get_sku();
        if (!empty($sku)) {
            $target_id = $this->find_product_by_sku($sku);
            if ($target_id) {
                $this->logger->info("Proizvod pronađen preko SKU", [
                    'product_id' => $product->get_id(),
                    'sku' => $sku,
                    'target_id' => $target_id
                ]);
                update_post_meta($product->get_id(), '_synced_product_id', $target_id);
                return $target_id;
            }
        }

        // 4. NOVA METODA: Pokušamo sa SKU-ovima varijacija
        if ($product->get_type() === 'variable') {
            $target_id = $this->find_product_by_variation_skus($product);
            if ($target_id) {
                $this->logger->info("Proizvod pronađen preko SKU varijacija", [
                    'product_id' => $product->get_id(),
                    'target_id' => $target_id
                ]);
                update_post_meta($product->get_id(), '_synced_product_id', $target_id);
                return $target_id;
            }
        }

        // 5. Pokušamo sa imenom proizvoda kao zadnju opciju
        $target_id = $this->find_product_by_name($product->get_name());
        if ($target_id) {
            $this->logger->info("Proizvod pronađen preko imena", [
                'product_id' => $product->get_id(),
                'name' => $product->get_name(),
                'target_id' => $target_id
            ]);
            update_post_meta($product->get_id(), '_synced_product_id', $target_id);
            return $target_id;
        }

        return false;
    }
    /**
     * Proverava da li proizvod sa datim ID-jem postoji na ciljnom sajtu
     */
    private function check_if_product_exists_by_id($product_id)
    {
        $endpoint = $this->build_api_endpoint("products/{$product_id}");
        $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    /**
     * Pronalazi proizvod po SKU na ciljnom sajtu
     */
    private function find_product_by_sku($sku)
    {
        if (empty($sku)) {
            return false;
        }

        $endpoint = $this->build_api_endpoint("products", ['sku' => $sku]);
        $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $products = json_decode(wp_remote_retrieve_body($response));
            if (!empty($products)) {
                $remote_product = reset($products);
                return $remote_product->id;
            }
        }

        return false;
    }
    /**
     * Pronalazi odgovarajuću varijaciju na ciljnom sajtu
     * 
     * @param WC_Product_Variation $variation Varijacija sa izvornog sajta
     * @param array $target_variations Varijacije sa ciljnog sajta
     * @return object|null Pronađena varijacija ili null
     */
    private function find_matching_target_variation($variation, $target_variations)
    {
        // Prvo pokušajte pronalaženje po SKU
        $sku = $variation->get_sku();
        if (!empty($sku)) {
            foreach ($target_variations as $target_variation) {
                if (isset($target_variation->sku) && $target_variation->sku === $sku) {
                    return $target_variation;
                }
            }
        }

        // Ako SKU ne uspe, pokušajte sa metom synced_variation_id
        $synced_id = get_post_meta($variation->get_id(), '_synced_variation_id', true);
        if (!empty($synced_id)) {
            foreach ($target_variations as $target_variation) {
                if ($target_variation->id == $synced_id) {
                    return $target_variation;
                }
            }
        }

        // Ako sve ostalo ne uspeva, pokušajte podudaranje atributa
        $variation_attributes = $variation->get_attributes();
        foreach ($target_variations as $target_variation) {
            $matches = true;

            foreach ($target_variation->attributes as $target_attr) {
                $attr_name = 'attribute_' . sanitize_title($target_attr->name);

                if (
                    !isset($variation_attributes[$attr_name]) ||
                    strtolower($variation_attributes[$attr_name]) !== strtolower($target_attr->option)
                ) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $target_variation;
            }
        }

        return null;
    }
}
