<?php

namespace Shopito_Sync;

class Image_Handler
{
    private $settings;
    private $image_cache = [];
    private $logger;

    public function __construct($settings)
    {
        $this->settings = $settings;
        $this->logger = Logger::get_instance();
    }

    private function log($message, $level = 'info', $context = [])
    {
        $this->logger->log($message, $level, $context);
    }

    private function check_image_exists_by_name($filename)
    {
        // Brišemo transient keš jer možda sadrži zastarele ID-jeve
        $cache_key = 'shopito_img_' . md5($filename);
        delete_transient($cache_key);

        // Prvo proverimo lokalni keš (za trenutnu sesiju)
        if (isset($this->image_cache[$filename])) {
            $this->logger->info("Slika pronađena u lokalnom kešu: {$filename}", [
                'cached_id' => $this->image_cache[$filename]
            ]);
            return $this->image_cache[$filename];
        }

        // Proveravamo bez -scaled varijante
        $base_filename = str_replace('-scaled', '', $filename);
        if (isset($this->image_cache[$base_filename])) {
            $this->logger->info("Slika pronađena u lokalnom kešu (bez scaled): {$base_filename}", [
                'cached_id' => $this->image_cache[$base_filename]
            ]);
            return $this->image_cache[$base_filename];
        }

        // API poziv za pretragu po imenu fajla
        $endpoint = add_query_arg([
            'search' => $filename,
            'per_page' => 10 // Povećan broj rezultata za bolju pretragu
        ], trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media');

        $this->logger->info("Pretraga slike na ciljnom sajtu: {$filename}", [
            'endpoint' => $endpoint
        ]);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
            ],
            'sslverify' => false,
            'timeout' => 30
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $media = json_decode(wp_remote_retrieve_body($response));
            foreach ($media as $item) {
                $item_filename = basename($item->source_url);

                // Probamo različite varijante imena (originalno, bez scaled)
                if (
                    $item_filename === $filename ||
                    str_replace('-scaled', '', $item_filename) === str_replace('-scaled', '', $filename)
                ) {

                    // Ažuriramo lokalni keš
                    $this->image_cache[$filename] = $item->id;
                    $this->image_cache[$base_filename] = $item->id;

                    // Ažuriramo transient keš sa kraćim trajanjem
                    set_transient($cache_key, $item->id, 1 * DAY_IN_SECONDS); // Skraćeno na 1 dan

                    $this->logger->info("Slika pronađena na ciljnom sajtu: {$filename}", [
                        'media_id' => $item->id
                    ]);

                    return $item->id;
                }
            }
        } else {
            $error_message = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            $this->logger->warning("Problem sa pretragom slike: {$filename}", [
                'error' => $error_message
            ]);
        }

        $this->logger->info("Slika nije pronađena na ciljnom sajtu: {$filename}");
        return false;
    }

    public function upload_image($image_url)
    {
        if (empty($image_url)) {
            $this->logger->warning("Prazan URL slike, preskačem");
            return false;
        }

        $filename = basename(parse_url($image_url, PHP_URL_PATH));

        // Uvek prvo proverimo da li slika već postoji, bez korišćenja keša
        $existing_id = $this->check_image_exists_by_name($filename);
        if ($existing_id) {
            $this->logger->info("Slika već postoji na ciljnom sajtu: {$filename}", [
                'existing_id' => $existing_id
            ]);
            return $existing_id;
        }

        // Preuzimanje slike
        $this->logger->info("Preuzimanje slike: {$image_url}");
        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            $this->logger->error("Greška pri preuzimanju slike: {$filename}", [
                'error' => $temp_file->get_error_message()
            ]);
            return false;
        }

        $file_type = wp_check_filetype($filename);
        $file_content = file_get_contents($temp_file);

        @unlink($temp_file); // Odmah čistimo temp fajl

        // Pokušajmo do 3 puta uploadati sliku
        $max_attempts = 3;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $endpoint = trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media';

            $this->logger->info("Upload slike (pokušaj {$attempt}): {$filename}");

            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password']),
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Type' => $file_type['type']
                ],
                'body' => $file_content,
                'timeout' => 120,
                'sslverify' => false
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201) {
                $media = json_decode(wp_remote_retrieve_body($response));
                if (isset($media->id)) {
                    $this->image_cache[$filename] = $media->id;
                    $base_filename = str_replace('-scaled', '', $filename);
                    $this->image_cache[$base_filename] = $media->id;

                    // Keširanje rezultata na kraći period
                    $cache_key = 'shopito_img_' . md5($filename);
                    set_transient($cache_key, $media->id, 1 * DAY_IN_SECONDS);

                    $this->logger->success("Uspešno otpremljena slika: {$filename}", [
                        'media_id' => $media->id
                    ]);
                    return $media->id;
                }
            } else {
                $error_message = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
                $this->logger->warning("Neuspeo pokušaj {$attempt} za upload slike: {$filename}", [
                    'error' => $error_message
                ]);

                // Sačekajmo malo pre ponovnog pokušaja
                if ($attempt < $max_attempts) {
                    sleep(1);
                }
            }
        }

        $this->logger->error("Slika nije uspešno uploadovana nakon {$max_attempts} pokušaja: {$filename}");
        return false;
    }

    public function prepare_product_images($product)
    {
        if (!$product) {
            return [];
        }

        $images = [];

        // Glavna slika
        if ($image_id = $product->get_image_id()) {
            if ($image_url = wp_get_attachment_url($image_id)) {
                if ($uploaded_id = $this->upload_image($image_url)) {
                    $images[] = [
                        'id' => $uploaded_id,
                        'src' => $image_url,
                        'position' => 0
                    ];
                }
            }
        }

        // Galerija
        $gallery_image_ids = $product->get_gallery_image_ids();
        foreach ($gallery_image_ids as $index => $gallery_image_id) {
            if ($image_url = wp_get_attachment_url($gallery_image_id)) {
                if ($uploaded_id = $this->upload_image($image_url)) {
                    $images[] = [
                        'id' => $uploaded_id,
                        'src' => $image_url,
                        'position' => $index + 1
                    ];
                }
            }
        }

        return $images;
    }

    public function prepare_variation_images($variation_id)
    {
        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return [];
        }

        $images = [];

        // Glavna slika varijacije
        if ($image_id = $variation->get_image_id()) {
            if ($image_url = wp_get_attachment_url($image_id)) {
                if ($uploaded_id = $this->upload_image($image_url)) {
                    $images['main'] = $uploaded_id;
                }
            }
        }

        // RTWPVG galerija
        $gallery_images = get_post_meta($variation_id, 'rtwpvg_images', true);
        if (!empty($gallery_images) && is_array($gallery_images)) {
            $gallery_ids = [];

            foreach ($gallery_images as $gallery_image_id) {
                if ($image_url = wp_get_attachment_url($gallery_image_id)) {
                    if ($uploaded_id = $this->upload_image($image_url)) {
                        $gallery_ids[] = $uploaded_id;
                    }
                }
            }

            if (!empty($gallery_ids)) {
                $images['gallery'] = $gallery_ids;
            }
        }

        return $images;
    }
}