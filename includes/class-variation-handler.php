<?php

namespace Shopito_Sync;

class Variation_Handler
{
    private $settings;
    private $api_handler;
    private $image_handler;

    public function __construct($settings, $api_handler)
    {
        $this->settings = $settings;
        $this->api_handler = $api_handler;
        $this->image_handler = new Image_Handler($settings);
    }

    private function log($message, $context = [])
    {
        // error_log("🔄 Shopito Sync: " . $message . ($context ? " | " . json_encode($context) : ""));
    }

    public function sync_variations($product_id, $target_product_id)
    {
        //$this->log("Sinhronizacija varijacija", ['source' => $product_id, 'target' => $target_product_id]);

        $product = wc_get_product($product_id);
        if (!$product || $product->get_type() !== 'variable') {
            return false;
        }

        if (
            !$this->generate_variations($target_product_id) ||
            !$this->wait_for_variations_generation($target_product_id)
        ) {
            return false;
        }

        $target_variations = $this->get_all_target_variations($target_product_id);
        $source_variations = $this->get_source_variations($product);

        foreach ($target_variations as $target_variation) {
            $matching_variation = $this->find_matching_source_variation(
                $target_variation,
                $source_variations
            );

            if ($matching_variation) {
                $this->update_matched_variation(
                    $matching_variation['data'],
                    $target_variation,
                    $target_product_id
                );
            }
        }

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
        $variation_data = $this->prepare_variation_data($variation_obj, $target_variation);
        $this->update_variation_data($target_product_id, $target_variation->id, $variation_data);
    }

    private function prepare_variation_data($variation_obj, $target_variation)
    {
        $parent_product = wc_get_product($variation_obj->get_parent_id());
        $variation_data = [
            'regular_price' => $this->api_handler->convert_price_to_bam($variation_obj->get_regular_price()),
            'sale_price' => $this->api_handler->convert_price_to_bam($variation_obj->get_sale_price()),
            'sku' => $variation_obj->get_sku(),
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
        $variation_images = $this->image_handler->prepare_variation_images($variation_obj->get_id());
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

        $response = wp_remote_post($endpoint, [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['delete' => false]),
            'timeout' => 100,
            'sslverify' => false
        ]);

        return !is_wp_error($response) &&
            in_array(wp_remote_retrieve_response_code($response), [200, 201]);
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

        $response = wp_remote_get($endpoint, ['sslverify' => false]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return json_decode(wp_remote_retrieve_body($response));
        }
        return [];
    }

    private function find_matching_source_variation($target_variation, $source_variations)
    {
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
                return $source_variation;
            }
        }

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
            ]);
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    private function wait_for_variations_generation($target_product_id, $max_attempts = 10)
    {
        for ($i = 0; $i < $max_attempts; $i++) {
            if (!empty($this->get_all_target_variations($target_product_id))) {
                return true;
            }
            usleep(500000); // 0.5 sekundi pauza
        }
        return false;
    }
}
