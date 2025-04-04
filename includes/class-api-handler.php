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


    public function __construct()
    {
        $this->settings = get_option('shopito_sync_settings');
        $this->attribute_handler = new Attribute_Handler($this->settings);
        $this->variation_handler = new Variation_Handler($this->settings, $this);
        $this->image_handler = new Image_Handler($this->settings);
    }

    private function make_api_request($endpoint, $args, $method = 'GET')
    {
        $attempt = 0;
        $last_error = null;

        while ($attempt < $this->max_retries) {
            if ($attempt > 0) {
                sleep($this->retry_delay * $attempt);
            }

            $response = wp_remote_request($endpoint, array_merge($args, ['method' => $method]));

            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code >= 200 && $response_code < 300) {
                    return $response;
                }
                if ($response_code === 429) {
                    sleep(10);
                }
            }

            $last_error = is_wp_error($response) ? $response : new \WP_Error('api_error', 'API Error: ' . $response_code);
            $attempt++;
        }

        return $last_error;
    }
    public function sync_product($product_id)
    {
        $steps = [];

        try {
            // 1. Inicijalna provera proizvoda
            $product = $this->validate_and_prepare_product($product_id);
            $existing_product_id = $this->check_if_product_exists($product);

            // 2. Batch procesiranje slika
            $steps[] = ['name' => 'images', 'status' => 'active', 'message' => 'Prebacivanje slika...'];
            $images = $this->process_images_in_batch($product);
            $steps[count($steps) - 1] = [
                'name' => 'images',
                'status' => 'completed',
                'message' => count($images) . ' slika(e) uploadovano'
            ];

            // 3. Priprema i slanje proizvoda
            $data = $this->prepare_product_data($product);
            $data['images'] = $images;

            $endpoint = $this->build_api_endpoint($existing_product_id ? "products/{$existing_product_id}" : "products");
            $method = $existing_product_id ? 'PUT' : 'POST';

            $response = $this->make_api_request($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
                ],
                'body' => json_encode($data),
                'timeout' => 600,
                'sslverify' => false
            ], $method);

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response));
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code !== 201 && $response_code !== 200) {
                throw new \Exception($this->get_error_message($body, $response_code));
            }

            // 4. Ažuriranje meta podataka
            update_post_meta($product_id, '_synced_product_id', $body->id);
            update_post_meta($product_id, '_last_sync_date', current_time('mysql'));

            // 5. Sinhronizacija varijacija ako je potrebno
            if ($product->get_type() === 'variable') {
                $steps[] = ['name' => 'variations', 'status' => 'active', 'message' => 'Kreiranje varijacija...'];
                $variation_result = $this->sync_product_variations($product_id, $body->id);
                $steps[] = $variation_result;
            }

            // 6. Konverzija cena
            $steps[] = [
                'name' => 'prices',
                'status' => 'completed',
                'message' => 'Cene konvertovane'
            ];

            // 7. DODATI: Korak za stanje
            $steps[] = [
                'name' => 'stock',
                'status' => 'completed',
                'message' => 'Stanje proizvoda ažurirano'
            ];

            return [
                'success' => true,
                'action' => $existing_product_id ? 'updated' : 'created',
                'steps' => $steps
            ];
        } catch (\Exception $e) {
            error_log('Shopito Sync Error: ' . $e->getMessage());
            return new \WP_Error('sync_error', $e->getMessage());
        }
    }
    private function process_images_in_batch($product)
    {
        $images = [];
        $batch_size = 3; // Procesiramo po 3 slike odjednom

        // Skupljamo sve slike (glavnu i galeriju)
        $image_ids = array_merge(
            [$product->get_image_id()],
            $product->get_gallery_image_ids()
        );
        $image_ids = array_filter($image_ids);

        // Procesiramo slike u batch-evima
        foreach (array_chunk($image_ids, $batch_size) as $batch) {
            $batch_promises = [];
            foreach ($batch as $index => $image_id) {
                if ($image_url = wp_get_attachment_url($image_id)) {
                    if ($uploaded_id = $this->image_handler->upload_image($image_url)) {
                        $images[] = [
                            'id' => $uploaded_id,
                            'src' => $image_url,
                            'position' => $index
                        ];
                    }
                }
            }
        }

        return $images;
    }
    private function validate_and_prepare_product($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            throw new \Exception('Proizvod nije pronađen');
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

    private function sync_product_variations($product_id, $target_product_id)
    {
        try {
            $variation_result = $this->variation_handler->sync_variations($product_id, $target_product_id);

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
     * Sinhronizuje samo stanje proizvoda
     * 
     * @param int $product_id ID proizvoda koji treba sinhronizovati
     * @return array|WP_Error Rezultat sinhronizacije
     */
    public function sync_product_stock($product_id)
    {
        $steps = [];

        try {
            // 1. Inicijalna provera proizvoda
            $product = $this->validate_and_prepare_product($product_id);
            $existing_product_id = $this->check_if_product_exists($product);

            if (!$existing_product_id) {
                throw new \Exception('Proizvod nije pronađen na ciljnom sajtu. Prvo izvršite punu sinhronizaciju.');
            }

            // 2. Pripremamo podatke samo za stanje
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

            // 3. Slanje na ciljni sajt
            $endpoint = $this->build_api_endpoint("products/{$existing_product_id}");
            $response = $this->make_api_request($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
                ],
                'body' => json_encode($data),
                'timeout' => 60,
                'sslverify' => false
            ], 'PUT');

            if (is_wp_error($response)) {
                throw new \Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response));
            $response_code = wp_remote_retrieve_response_code($response);

            if ($response_code !== 200) {
                throw new \Exception($this->get_error_message($body, $response_code));
            }

            // 4. Sinhronizacija varijacija ako je potrebno
            if ($product->get_type() === 'variable') {
                $steps[] = ['name' => 'variations', 'status' => 'active', 'message' => 'Ažuriranje stanja varijacija...'];
                $variation_result = $this->sync_variations_stock($product, $existing_product_id);
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
            error_log('Shopito Sync Stock Error: ' . $e->getMessage());
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
