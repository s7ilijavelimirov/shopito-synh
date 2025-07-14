<?php

namespace Shopito_Sync;

class Variation_Handler
{
    private $settings;
    private $api_handler;
    private $image_handler;
    private $logger;

    public function __construct($settings, $api_handler)
    {
        $this->settings = $settings;
        $this->api_handler = $api_handler;
        $this->image_handler = new Image_Handler($settings);
        $this->logger = Logger::get_instance();
    }

    /**
     * Logging metoda koja proverava da li je logovanje omogućeno
     */
    private function log($message, $context = [], $level = 'info')
    {
        $this->logger->log($message, $level, $context);
    }

    public function sync_variations($product_id, $target_product_id)
    {
        $this->log("Započeta sinhronizacija varijacija", [
            'source' => $product_id,
            'target' => $target_product_id
        ]);

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'variable') {
            $this->log("Proizvod nije varijabilnog tipa", [
                'product_id' => $product_id,
                'type' => $product ? $product->get_type() : 'null'
            ], 'error');
            return false;
        }

        if (
            !$this->generate_variations($target_product_id) ||
            !$this->wait_for_variations_generation($target_product_id)
        ) {
            $this->log("Neuspela generacija varijacija", [
                'target_product_id' => $target_product_id
            ], 'error');
            return false;
        }

        $target_variations = $this->get_all_target_variations($target_product_id);
        $source_variations = $this->get_source_variations($product);

        $this->log("Pronađeno varijacija", [
            'target_count' => count($target_variations),
            'source_count' => count($source_variations)
        ]);

        $matched_count = 0;
        foreach ($target_variations as $target_variation) {
            $matching_variation = $this->find_matching_source_variation(
                $target_variation,
                $source_variations
            );

            if ($matching_variation) {
                $matched_count++;
                $this->update_matched_variation(
                    $matching_variation['data'],
                    $target_variation,
                    $target_product_id
                );

                // Sačuvamo ID varijacije na ciljnom sajtu za buduće sinhronizacije
                update_post_meta($matching_variation['variation_id'], '_synced_variation_id', $target_variation->id);
            }
        }

        $this->log("Sinhronizacija varijacija završena", [
            'product_id' => $product_id,
            'target_product_id' => $target_product_id,
            'matched_variations' => $matched_count
        ], 'success');

        return true;
    }

    private function get_source_variations($product)
    {
        $source_variations = [];
        $variations = $product->get_children();

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if ($variation) {
                $source_variations[] = [
                    'variation_id' => $variation_id,
                    'attributes' => $this->get_variation_attributes($variation),
                    'data' => $variation
                ];
            }
        }

        return $source_variations;
    }

    private function update_matched_variation($variation_obj, $target_variation, $target_product_id)
    {
        $variation_data = $this->prepare_variation_data($variation_obj, $target_variation, 0, $target_product_id);
        $result = $this->update_variation_data($target_product_id, $target_variation->id, $variation_data);

        if ($result) {
            $this->log("Varijacija uspešno ažurirana", [
                'variation_id' => $variation_obj->get_id(),
                'target_variation_id' => $target_variation->id
            ], 'success');
        }
    }
    private function check_sku_exists($sku, $variation_id = 0)
    {
        if (empty($sku)) return false;

        $endpoint = add_query_arg(
            [
                'consumer_key' => $this->settings['consumer_key'],
                'consumer_secret' => $this->settings['consumer_secret'],
                'sku' => $sku
            ],
            trailingslashit($this->settings['target_url']) . "wp-json/wc/v3/products"
        );

        $response = wp_remote_get($endpoint, ['sslverify' => false]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $products = json_decode(wp_remote_retrieve_body($response));

            if (!empty($products)) {
                // Prvo proveravamo da li je SKU već dodeljen istoj varijaciji
                foreach ($products as $product) {
                    if ($product->id == $variation_id) {
                        return false; // SKU već pripada ovoj varijaciji, što je ok
                    }
                }

                // Ako dođemo dovde, SKU postoji ali na drugom proizvodu
                return true;
            }
        }

        return false; // SKU ne postoji na ciljnom sajtu
    }
    private function prepare_variation_data($variation_obj, $target_variation, $variation_index = 0, $target_product_id = null)
    {
        $parent_product = wc_get_product($variation_obj->get_parent_id());

        $variation_data = [
            'regular_price' => $this->api_handler->convert_price_to_bam($variation_obj->get_regular_price()),
            'sale_price' => $this->api_handler->convert_price_to_bam($variation_obj->get_sale_price()),
            'stock_quantity' => $variation_obj->get_stock_quantity(),
            'manage_stock' => $variation_obj->get_manage_stock(),
            'stock_status' => $variation_obj->get_stock_status(),
            'description' => $variation_obj->get_description(),
            'attributes' => $this->prepare_attributes_for_api($target_variation->attributes),
            'weight' => $variation_obj->get_weight(),

            // Meta podaci
            'meta_data' => [
                [
                    'key' => '_alg_ean',
                    'value' => get_post_meta($variation_obj->get_id(), '_alg_ean', true)
                        ?: get_post_meta($variation_obj->get_id(), '_ean', true)
                ],
                [
                    'key' => '_purchase_price',
                    'value' => get_post_meta($variation_obj->get_id(), '_purchase_price', true)
                ],
                [
                    'key' => '_minimum_quantity',
                    'value' => get_post_meta($variation_obj->get_id(), '_minimum_quantity', true)
                ]
            ]
        ];

        // Provera i dodavanje SKU-a
        $variation_sku = $variation_obj->get_sku();
        $parent_sku = $parent_product ? $parent_product->get_sku() : '';

        // Proveri da li je SKU validan (nije prazan i nije dupliciran)
        $is_valid_sku = !empty($variation_sku) && $variation_sku !== $parent_sku;

        // Dodaj SKU samo ako je validan
        if ($is_valid_sku) {
            $normalized_sku = trim($variation_sku);

            // Provera da li SKU već postoji na ciljnom sajtu (na drugom proizvodu)
            if (!$this->check_sku_exists($normalized_sku, $target_variation->id)) {
                $variation_data['sku'] = $normalized_sku;
                $this->log("Korišten postojeći SKU za varijaciju", [
                    'variation_id' => $variation_obj->get_id(),
                    'sku' => $normalized_sku
                ], 'info');
            } else {
                // SKU već postoji negde drugde - ne dodajemo ga
                $this->log("SKU već postoji na drugom proizvodu, preskačem dodavanje SKU", [
                    'variation_id' => $variation_obj->get_id(),
                    'sku' => $normalized_sku
                ], 'warning');
                // Ne dodajemo SKU u variation_data, tako da će ostati kao što je na ciljnom sajtu
            }
        } else {
            $this->log("Preskočen problematični SKU za varijaciju", [
                'variation_id' => $variation_obj->get_id(),
                'original_sku' => $variation_sku,
                'parent_sku' => $parent_sku
            ], 'warning');
            // Ovde namerno ne dodajemo SKU u variation_data
        }

        $length = $variation_obj->get_length();
        $width = $variation_obj->get_width();
        $height = $variation_obj->get_height();

        // Provera da li su dimenzije eksplicitno definisane za varijantu
        if ($length > 0 || $width > 0 || $height > 0) {
            $variation_data['dimensions'] = [
                'length' => $length,
                'width'  => $width,
                'height' => $height
            ];
        }

        // Dodavanje slika
        $variation_images = $this->image_handler->prepare_variation_images($variation_obj->get_id(), $target_product_id);
        if (!empty($variation_images)) {
            if (isset($variation_images['main'])) {
                $variation_data['image'] = ['id' => $variation_images['main']];
            }
            if (isset($variation_images['gallery']) && !empty($variation_images['gallery'])) {
                $variation_data['meta_data'][] = [
                    'key' => 'rtwpvg_images',
                    'value' => $variation_images['gallery']
                ];
                $variation_data['meta_data'][] = [
                    'key' => '_gallery_images',
                    'value' => $variation_images['gallery']
                ];
            }
        }

        return $variation_data;
    }

    private function get_variation_attributes($variation)
    {
        $attributes = [];
        foreach ($variation->get_attributes() as $attribute => $value) {
            $taxonomy = str_replace('attribute_', '', $attribute);
            $term = get_term_by('slug', $value, $taxonomy);
            $attributes[$attribute] = $term ? $term->name : $value;
        }
        return $attributes;
    }

    private function prepare_attributes_for_api($attributes)
    {
        return array_map(function ($attr) {
            return [
                'id' => (int)$attr->id,
                'name' => $attr->slug,
                'option' => $attr->option
            ];
        }, $attributes);
    }

    private function generate_variations($target_product_id)
    {
        $endpoint = add_query_arg(
            [
                'consumer_key' => $this->settings['consumer_key'],
                'consumer_secret' => $this->settings['consumer_secret']
            ],
            trailingslashit($this->settings['target_url']) .
                'wp-json/wc/v3/products/' . $target_product_id . '/variations/generate'
        );

        $this->log("Generisanje varijacija", [
            'target_product_id' => $target_product_id,
            'endpoint' => $endpoint
        ]);

        $response = wp_remote_post($endpoint, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['delete' => false]),
            'timeout' => 100,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            $this->log("Greška pri generisanju varijacija", [
                'target_product_id' => $target_product_id,
                'error' => $response->get_error_message()
            ], 'error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (!in_array($response_code, [200, 201])) {
            $this->log("API greška pri generisanju varijacija", [
                'target_product_id' => $target_product_id,
                'response_code' => $response_code,
                'response' => wp_remote_retrieve_body($response)
            ], 'error');
            return false;
        }

        $this->log("Varijacije uspešno generisane", [
            'target_product_id' => $target_product_id
        ], 'success');

        return true;
    }

    private function get_all_target_variations($product_id)
    {
        $endpoint = add_query_arg(
            [
                'consumer_key' => $this->settings['consumer_key'],
                'consumer_secret' => $this->settings['consumer_secret'],
                'per_page' => 100
            ],
            trailingslashit($this->settings['target_url']) .
                "wp-json/wc/v3/products/{$product_id}/variations"
        );

        $this->log("Dobavljanje varijacija sa ciljnog sajta", [
            'product_id' => $product_id
        ]);

        $response = wp_remote_get($endpoint, ['sslverify' => false, 'timeout' => 30]);

        if (is_wp_error($response)) {
            $this->log("Greška pri dobavljanju varijacija", [
                'product_id' => $product_id,
                'error' => $response->get_error_message()
            ], 'error');
            return [];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log("API greška pri dobavljanju varijacija", [
                'product_id' => $product_id,
                'response_code' => $response_code,
                'response' => wp_remote_retrieve_body($response)
            ], 'error');
            return [];
        }

        $variations = json_decode(wp_remote_retrieve_body($response));
        $this->log("Uspešno dobavljene varijacije", [
            'product_id' => $product_id,
            'count' => count($variations)
        ], 'success');

        return $variations;
    }

    private function find_matching_source_variation($target_variation, $source_variations)
    {
        // Prvo pokušamo da nađemo varijantu koja je već sinhronizovana sa ovom ciljnom varijantom
        foreach ($source_variations as $source_variation) {
            $synced_id = get_post_meta($source_variation['variation_id'], '_synced_variation_id', true);
            if ($synced_id && $synced_id == $target_variation->id) {
                $this->log("Pronađena prethodno sinhronizovana varijacija", [
                    'source_id' => $source_variation['variation_id'],
                    'target_id' => $target_variation->id
                ]);
                return $source_variation;
            }
        }

        // Pokušamo sa podudaranjem atributa
        foreach ($source_variations as $source_variation) {
            $matches = true;

            foreach ($target_variation->attributes as $target_attr) {
                $source_attr_names = [
                    'pa_' . strtolower($target_attr->slug),
                    'pa_' . iconv('UTF-8', 'ASCII//TRANSLIT', strtolower($target_attr->slug)),
                    $target_attr->slug
                ];

                $source_value = null;
                foreach ($source_attr_names as $attr_name) {
                    if (isset($source_variation['attributes'][$attr_name])) {
                        $source_value = $source_variation['attributes'][$attr_name];
                        break;
                    }
                }

                if (!$source_value || strcasecmp($source_value, $target_attr->option) !== 0) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $this->log("Pronađena varijacija po atributima", [
                    'source_id' => $source_variation['variation_id'],
                    'target_id' => $target_variation->id
                ]);
                return $source_variation;
            }
        }

        // Pokušamo sa podudaranjem SKU-a
        if (!empty($target_variation->sku)) {
            foreach ($source_variations as $source_variation) {
                $source_sku = $source_variation['data']->get_sku();
                if (!empty($source_sku) && $source_sku === $target_variation->sku) {
                    $this->log("Pronađena varijacija po SKU", [
                        'source_id' => $source_variation['variation_id'],
                        'target_id' => $target_variation->id,
                        'sku' => $source_sku
                    ]);
                    return $source_variation;
                }
            }
        }

        $this->log("Nije pronađena odgovarajuća varijacija", [
            'target_id' => $target_variation->id
        ], 'warning');

        return null;
    }

    private function update_variation_data($product_id, $variation_id, $data)
    {
        $endpoint = add_query_arg(
            [
                'consumer_key' => $this->settings['consumer_key'],
                'consumer_secret' => $this->settings['consumer_secret']
            ],
            trailingslashit($this->settings['target_url']) .
                "wp-json/wc/v3/products/{$product_id}/variations/{$variation_id}"
        );

        $this->log("Ažuriranje varijacije", [
            'product_id' => $product_id,
            'variation_id' => $variation_id
        ]);

        $response = wp_remote_post($endpoint, [
            'method' => 'PUT',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data),
            'timeout' => 300,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            $this->log("Greška pri ažuriranju varijacije", [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'error' => $response->get_error_message()
            ], 'error');
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->log("API greška pri ažuriranju varijacije", [
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'response_code' => $response_code,
                'response' => wp_remote_retrieve_body($response)
            ], 'error');
            return false;
        }

        return true;
    }

    private function wait_for_variations_generation($target_product_id, $max_attempts = 10)
    {
        $this->log("Čekanje na generisanje varijacija", [
            'target_product_id' => $target_product_id,
            'max_attempts' => $max_attempts
        ]);

        for ($i = 0; $i < $max_attempts; $i++) {
            $variations = $this->get_all_target_variations($target_product_id);

            if (!empty($variations)) {
                $this->log("Varijacije su generisane", [
                    'target_product_id' => $target_product_id,
                    'attempts' => $i + 1,
                    'count' => count($variations)
                ], 'success');
                return true;
            }

            $this->log("Varijacije još nisu generisane, pokušaj " . ($i + 1), [
                'target_product_id' => $target_product_id
            ]);

            // Eksponencijalni backoff - postepeno povećanje vremena čekanja
            $sleep_time = 500000 * pow(1.5, $i); // počinje sa 0.5s, pa 0.75s, 1.125s itd.
            usleep($sleep_time);
        }

        $this->log("Timeout pri čekanju na generisanje varijacija", [
            'target_product_id' => $target_product_id,
            'max_attempts' => $max_attempts
        ], 'error');

        return false;
    }
}
