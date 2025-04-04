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
        // Prvo proveravamo keš za tačno ime
        if (isset($this->image_cache[$filename])) {
            return $this->image_cache[$filename];
        }

        // Proveravamo keš za verziju bez -scaled
        $base_filename = str_replace('-scaled', '', $filename);
        if (isset($this->image_cache[$base_filename])) {
            return $this->image_cache[$base_filename];
        }

        // Ako nije u kešu, proveravamo API
        $endpoint = add_query_arg([
            'search' => $filename,
            'per_page' => 1
        ], trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media');

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
                if ($item_filename === $filename || str_replace('-scaled', '', $item_filename) === $base_filename) {
                    // Keširamo obe verzije imena
                    $this->image_cache[$filename] = $item->id;
                    $this->image_cache[$base_filename] = $item->id;
                    return $item->id;
                }
            }
        }

        return false;
    }

    public function upload_image($image_url)
    {
        if (empty($image_url)) {
            return false;
        }

        $filename = basename(parse_url($image_url, PHP_URL_PATH));

        // Prvo proverimo da li slika već postoji
        $existing_id = $this->check_image_exists_by_name($filename);
        if ($existing_id) {
            $this->log("Slika već postoji na ciljnom sajtu: {$filename}", 'info', ['existing_id' => $existing_id]);
            return $existing_id;
        }

        // Preuzimanje slike
        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            $this->log("Greška pri preuzimanju slike: {$filename}", 'error', ['error' => $temp_file->get_error_message()]);
            return false;
        }

        $file_type = wp_check_filetype($filename);
        $file_content = file_get_contents($temp_file);

        @unlink($temp_file); // Odmah čistimo temp fajl

        $endpoint = trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media';

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password']),
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Type' => $file_type['type']
            ],
            'body' => $file_content,
            'timeout' => 60,
            'sslverify' => false
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201) {
            $media = json_decode(wp_remote_retrieve_body($response));
            if (isset($media->id)) {
                $this->image_cache[$filename] = $media->id;
                $this->log("Uspešno otpremljena slika: {$filename}", 'success', ['media_id' => $media->id]);
                return $media->id;
            }
        } else {
            $error_message = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
            $this->log("Greška pri otpremanju slike: {$filename}", 'error', ['error' => $error_message]);
        }

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
