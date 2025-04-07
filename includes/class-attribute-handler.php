<?php

namespace Shopito_Sync;

class Attribute_Handler
{
    private $settings;
    private $cached_attributes = null;
    private $cached_terms = [];

    private $attribute_mapping = [
        'boja' => 'Boja',
        'materijal' => 'Materijal',
        'velicina' => 'Veličina',
        'brand' => 'Brend',
        'dezen-navlake-za-kofer' => 'Dezen navlake za kofer',
        'model' => 'Model',
        'pol' => 'Pol',
        'tezina_proizvoda' => 'Težina',
        'miris' => 'Miris',
        'zapremina_proizvoda' => 'Zapremina'
    ];

    public function __construct($settings)
    {
        $this->settings = $settings;
    }

    /**
     * Kešira sve atribute sa ciljnog sajta
     */

    private function get_cached_attributes()
    {
        if ($this->cached_attributes === null) {
            $this->cache_target_attributes();
        }
        return $this->cached_attributes;
    }
    /**
     * Kešira sve atribute sa ciljnog sajta
     */
    private function cache_target_attributes()
    {
        if ($this->cached_attributes !== null) {
            return; // Već je keširano u memoriji
        }

        // Ključ za transient zavisi od URL-a
        $cache_key = 'shopito_attr_' . md5($this->settings['target_url']);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            $this->cached_attributes = $cached_data;
            return;
        }

        $this->cached_attributes = [];

        $endpoint = trailingslashit($this->settings['target_url']) . 'wp-json/wc/v3/products/attributes';
        $endpoint = add_query_arg([
            'consumer_key' => $this->settings['consumer_key'],
            'consumer_secret' => $this->settings['consumer_secret'],
            'per_page' => 100
        ], $endpoint);

        $response = wp_remote_get($endpoint, [
            'sslverify' => false,
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json']
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return;
        }

        $attributes = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($attributes) || empty($attributes)) {
            return;
        }

        foreach ($attributes as $attr) {
            if (!isset($attr['slug']) || !isset($attr['name'])) {
                continue;
            }

            $this->cached_attributes[$attr['slug']] = $attr;
            $this->cached_attributes[$attr['name']] = $attr;
        }

        // Čuvamo u transient na 24h
        set_transient($cache_key, $this->cached_attributes, DAY_IN_SECONDS);
    }

    /**
     * Priprema atribute proizvoda za sinhronizaciju
     */
    public function prepare_product_attributes($product)
    {
        $prepared_attributes = [];

        foreach ($product->get_attributes() as $attribute) {
            $attribute_data = $attribute->get_data();
            $taxonomy = $attribute->get_taxonomy();

            $raw_name = str_replace('pa_', '', $taxonomy ?: $attribute_data['name']);
            $mapped_name = $this->attribute_mapping[$raw_name] ?? ucfirst($raw_name);

            $target_attribute = $this->get_target_attribute($mapped_name);

            if (!$target_attribute) {
                continue;
            }

            $terms = $this->prepare_attribute_terms($attribute, $target_attribute['id']);

            if (!empty($terms)) {
                $prepared_attributes[] = [
                    'id' => $target_attribute['id'],
                    'name' => $target_attribute['name'],
                    'position' => $attribute_data['position'],
                    'visible' => true,
                    'variation' => $attribute_data['variation'],
                    'options' => array_unique($terms)
                ];
            }
        }

        return $prepared_attributes;
    }

    /**
     * Pronalazi atribut na ciljnom sajtu
     */
    private function get_target_attribute($mapped_name)
    {
        $cached_attributes = $this->get_cached_attributes(); // Koristimo getter umesto direktnog pristupa

        foreach ($cached_attributes as $attr) {
            if (!isset($attr['name'])) {
                continue;
            }

            if (strcasecmp($attr['name'], $mapped_name) === 0) {
                return $attr;
            }
        }

        return false;
    }

    /**
     * Priprema termine za atribut
     */
    private function prepare_attribute_terms($attribute, $target_attribute_id)
    {
        $terms = [];
        $options = $attribute->get_options();

        if (!isset($this->cached_terms[$target_attribute_id])) {
            $this->cache_attribute_terms($target_attribute_id);
        }

        foreach ($options as $option) {
            if (is_numeric($option)) {
                $term = get_term($option);
                if ($term && !is_wp_error($term)) {
                    $term_value = $term->name;
                } else {
                    continue;
                }
            } else {
                $term_value = $option;
            }

            $normalized_term = $this->normalize_term_value($term_value);

            $found = false;
            foreach ($this->cached_terms[$target_attribute_id] as $target_term) {
                if ($this->normalize_term_value($target_term['name']) === $normalized_term) {
                    $terms[] = $target_term['name'];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $terms[] = $term_value;
            }
        }

        return array_unique($terms);
    }

    /**
     * Kešira termine za specifični atribut
     */
    private function cache_attribute_terms($attribute_id)
    {
        $endpoint = trailingslashit($this->settings['target_url']) . 'wp-json/wc/v3/products/attributes/' . $attribute_id . '/terms';
        $endpoint = add_query_arg([
            'consumer_key' => $this->settings['consumer_key'],
            'consumer_secret' => $this->settings['consumer_secret'],
            'per_page' => 100
        ], $endpoint);

        $response = wp_remote_get($endpoint, ['sslverify' => false]);

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        $this->cached_terms[$attribute_id] = is_array($response_body) ? $response_body : [];
    }

    /**
     * Normalizuje vrednost termina za pouzdano poređenje
     */
    private function normalize_term_value($value)
    {
        $normalized = str_replace('-', ' ', $value);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return strtolower(trim($normalized));
    }
}
