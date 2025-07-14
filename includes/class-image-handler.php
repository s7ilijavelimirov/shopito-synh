<?php

namespace Shopito_Sync;

class Image_Handler
{
    private $settings;
    private $image_cache = [];
    private $logger;
    private static $global_image_cache = [];
    public function __construct($settings)
    {
        $this->settings = $settings;
        $this->logger = Logger::get_instance();
    }
    /**
     * NOVA METODA: Batch upload svih slika za sve varijacije odjednom
     */
    public function batch_prepare_all_variation_images($variations, $target_product_id = null)
    {
        $all_urls = [];
        $variation_url_map = [];

        $this->log("Batch priprema slika za sve varijacije", 'info', ['variations_count' => count($variations)]);

        // Skupljamo sve URL-ove odjednom
        foreach ($variations as $variation_data) {
            $variation_id = $variation_data['variation_id'];
            $variation = $variation_data['data'];

            $urls = [];

            // Glavna slika
            if ($image_id = $variation->get_image_id()) {
                if ($image_url = wp_get_attachment_url($image_id)) {
                    $urls['main'] = $image_url;
                    $all_urls[] = $image_url;
                }
            }

            // Galerija
            $gallery_images = get_post_meta($variation_id, 'rtwpvg_images', true);
            if (!empty($gallery_images) && is_array($gallery_images)) {
                $urls['gallery'] = [];
                foreach ($gallery_images as $gallery_image_id) {
                    if ($image_url = wp_get_attachment_url($gallery_image_id)) {
                        $urls['gallery'][] = $image_url;
                        $all_urls[] = $image_url;
                    }
                }
            }

            $variation_url_map[$variation_id] = $urls;
        }

        if (empty($all_urls)) {
            $this->log("Nema slika za procesiranje");
            return [];
        }

        // Batch pretraga postojećih slika
        $all_filenames = array_map(function ($url) {
            return basename(parse_url($url, PHP_URL_PATH));
        }, array_unique($all_urls));

        $found_images = $this->batch_search_images($all_filenames, $target_product_id);

        // Batch upload samo onih koje nije našao
        $urls_to_upload = [];
        foreach (array_unique($all_urls) as $url) {
            $filename = basename(parse_url($url, PHP_URL_PATH));
            if (!isset($found_images[$filename])) {
                $urls_to_upload[] = $url;
            }
        }

        $uploaded_images = [];
        if (!empty($urls_to_upload)) {
            $uploaded_images = $this->batch_upload_images($urls_to_upload);
        }

        // Mapiranje rezultata nazad na varijacije
        $result = [];
        foreach ($variation_url_map as $variation_id => $urls) {
            $images = [];

            if (isset($urls['main'])) {
                $filename = basename(parse_url($urls['main'], PHP_URL_PATH));
                if (isset($found_images[$filename])) {
                    $images['main'] = $found_images[$filename];
                } elseif (isset($uploaded_images[$urls['main']])) {
                    $images['main'] = $uploaded_images[$urls['main']];
                }
            }

            if (!empty($urls['gallery'])) {
                $images['gallery'] = [];
                foreach ($urls['gallery'] as $gallery_url) {
                    $filename = basename(parse_url($gallery_url, PHP_URL_PATH));
                    if (isset($found_images[$filename])) {
                        $images['gallery'][] = $found_images[$filename];
                    } elseif (isset($uploaded_images[$gallery_url])) {
                        $images['gallery'][] = $uploaded_images[$gallery_url];
                    }
                }
            }

            $result[$variation_id] = $images;
        }

        $this->log("Batch priprema završena", 'info', [
            'total_variations' => count($variations),
            'total_images' => count($all_urls),
            'found_existing' => count($found_images),
            'uploaded_new' => count($uploaded_images)
        ]);

        return $result;
    }
    /**
     * Batch pretraga slika na ciljnom sajtu
     */
    private function batch_search_images($filenames, $product_id = null)
    {
        $batch_size = 15;
        $found_images = [];

        foreach (array_chunk($filenames, $batch_size) as $batch) {
            $search_terms = implode(' OR ', array_map(function ($filename) {
                return '"' . pathinfo($filename, PATHINFO_FILENAME) . '"';
            }, $batch));

            $endpoint = add_query_arg([
                'search' => $search_terms,
                'per_page' => $batch_size * 2
            ], trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media');

            $response = wp_remote_get($endpoint, [
                'headers' => ['Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])],
                'sslverify' => false,
                'timeout' => 30
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $media_items = json_decode(wp_remote_retrieve_body($response));

                foreach ($media_items as $item) {
                    $item_filename = basename($item->source_url);
                    foreach ($batch as $original_filename) {
                        if (
                            $item_filename === $original_filename ||
                            str_replace('-scaled', '', $item_filename) === str_replace('-scaled', '', $original_filename)
                        ) {
                            if (!$product_id || $this->is_image_attached_to_product($item->id, $product_id)) {
                                $found_images[$original_filename] = $item->id;
                                $this->image_cache[$original_filename] = $item->id;
                                self::$global_image_cache[$original_filename] = $item->id;
                            }
                        }
                    }
                }
            }
            usleep(100000); // 0.1s pauza između batch-eva
        }

        return $found_images;
    }
    private function batch_upload_images($image_urls)
    {
        $uploaded_ids = [];
        $batch_size = 3; // Smanjeno jer upload traje duže

        foreach (array_chunk($image_urls, $batch_size) as $batch) {
            foreach ($batch as $url) {
                if ($uploaded_id = $this->upload_image($url)) {
                    $uploaded_ids[$url] = $uploaded_id;
                }
            }
            usleep(200000); // 0.2s pauza između batch-eva
        }

        return $uploaded_ids;
    }
    /**
     * Proverava da li je slika stvarno dodeljena proizvodu
     */
    private function is_image_attached_to_product($image_id, $product_id)
    {
        $endpoint = add_query_arg([
            'consumer_key' => $this->settings['consumer_key'],
            'consumer_secret' => $this->settings['consumer_secret']
        ], trailingslashit($this->settings['target_url']) . 'wp-json/wc/v3/products/' . $product_id);

        $response = wp_remote_get($endpoint, [
            'sslverify' => false,
            'timeout' => 30
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $product_data = json_decode(wp_remote_retrieve_body($response), true);

        // Proveri glavnu sliku
        if (isset($product_data['image']['id']) && $product_data['image']['id'] == $image_id) {
            return true;
        }

        // Proveri galeriju
        if (isset($product_data['images'])) {
            foreach ($product_data['images'] as $img) {
                if (isset($img['id']) && $img['id'] == $image_id) {
                    return true;
                }
            }
        }

        return false;
    }
    private function log($message, $level = 'info', $context = [])
    {
        $this->logger->log($message, $level, $context);
    }

    private function check_image_exists_by_name($filename, $product_id = null)
    {
        // Brišemo transient keš jer možda sadrži zastarele ID-jeve
        $cache_key = 'shopito_img_' . md5($filename);
        delete_transient($cache_key);

        // Prvo proverimo lokalni keš (za trenutnu sesiju)
        if (isset($this->image_cache[$filename])) {
            $cached_id = $this->image_cache[$filename];

            // NOVA PROVERA: Da li je slika stvarno dodeljena proizvodu
            if ($product_id && !$this->is_image_attached_to_product($cached_id, $product_id)) {
                $this->logger->info("Slika postoji u kešu ali nije dodeljena proizvodu: {$filename}", [
                    'image_id' => $cached_id,
                    'product_id' => $product_id
                ]);

                // Ukloni iz keša i nastavi sa upload-om
                unset($this->image_cache[$filename]);
                delete_transient($cache_key);
            } else {
                $this->logger->info("Slika pronađena u lokalnom kešu: {$filename}", [
                    'cached_id' => $cached_id
                ]);
                return $cached_id;
            }
        }

        // Proveravamo bez -scaled varijante
        $base_filename = str_replace('-scaled', '', $filename);
        if (isset($this->image_cache[$base_filename])) {
            $cached_id = $this->image_cache[$base_filename];

            // NOVA PROVERA i za base filename
            if ($product_id && !$this->is_image_attached_to_product($cached_id, $product_id)) {
                $this->logger->info("Base slika postoji u kešu ali nije dodeljena proizvodu: {$base_filename}", [
                    'image_id' => $cached_id,
                    'product_id' => $product_id
                ]);

                unset($this->image_cache[$base_filename]);
                delete_transient('shopito_img_' . md5($base_filename));
            } else {
                $this->logger->info("Slika pronađena u lokalnom kešu (bez scaled): {$base_filename}", [
                    'cached_id' => $cached_id
                ]);
                return $cached_id;
            }
        }

        // API poziv za pretragu po imenu fajla
        $endpoint = add_query_arg([
            'search' => $filename,
            'per_page' => 10
        ], trailingslashit($this->settings['target_url']) . 'wp-json/wp/v2/media');

        $this->logger->info("Pretraga slike na ciljnom sajtu: {$filename}", [
            'endpoint' => $endpoint
        ]);

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->settings['username'] . ':' . $this->settings['password'])
            ],
            'sslverify' => false,
            'timeout' => 20
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

                    // NOVA PROVERA: Da li je slika dodeljena proizvodu
                    if ($product_id && !$this->is_image_attached_to_product($item->id, $product_id)) {
                        $this->logger->info("Slika postoji u media library ali nije dodeljena proizvodu: {$filename}", [
                            'media_id' => $item->id,
                            'product_id' => $product_id
                        ]);
                        continue; // Nastavi pretragu
                    }

                    // Ažuriramo lokalni keš
                    $this->image_cache[$filename] = $item->id;
                    $this->image_cache[$base_filename] = $item->id;

                    // Ažuriramo transient keš sa kraćim trajanjem
                    set_transient($cache_key, $item->id, 1 * DAY_IN_SECONDS);

                    $this->logger->info("Slika pronađena na ciljnom sajtu i dodeljena proizvodu: {$filename}", [
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

        $this->logger->info("Slika nije pronađena na ciljnom sajtu ili nije dodeljena proizvodu: {$filename}");
        return false;
    }

    public function upload_image($image_url)
    {
        if (empty($image_url)) {
            $this->logger->warning("Prazan URL slike, preskačem");
            return false;
        }

        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (isset(self::$global_image_cache[$image_url])) {
            $this->logger->info("Slika pronađena u globalnom kešu: {$filename}", [
                'cached_id' => self::$global_image_cache[$image_url]
            ]);
            return self::$global_image_cache[$image_url];
        }
        // Uvek prvo proverimo da li slika već postoji, bez korišćenja keša
        $existing_id = $this->check_image_exists_by_name($filename);
        if ($existing_id) {
            // DODAJ: Sačuvaj u globalnom kešu
            self::$global_image_cache[$image_url] = $existing_id;

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
                    self::$global_image_cache[$image_url] = $media->id;
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
                    usleep(300000); // 0.3 sekunde umesto 1 sekunde
                }
            }
        }

        $this->logger->error("Slika nije uspešno uploadovana nakon {$max_attempts} pokušaja: {$filename}");
        return false;
    }

    public function prepare_product_images($product, $target_product_id = null)
    {
        if (!$product) {
            return [];
        }

        $images = [];

        // Glavna slika
        if ($image_id = $product->get_image_id()) {
            if ($image_url = wp_get_attachment_url($image_id)) {
                $filename = basename(parse_url($image_url, PHP_URL_PATH));

                // Proveravamo sa product_id
                $existing_id = $this->check_image_exists_by_name($filename, $target_product_id);

                if ($existing_id) {
                    $images[] = [
                        'id' => $existing_id,
                        'src' => $image_url,
                        'position' => 0
                    ];
                } else {
                    if ($uploaded_id = $this->upload_image($image_url)) {
                        $images[] = [
                            'id' => $uploaded_id,
                            'src' => $image_url,
                            'position' => 0
                        ];
                    }
                }
            }
        }

        // Galerija
        $gallery_image_ids = $product->get_gallery_image_ids();
        foreach ($gallery_image_ids as $index => $gallery_image_id) {
            if ($image_url = wp_get_attachment_url($gallery_image_id)) {
                $filename = basename(parse_url($image_url, PHP_URL_PATH));

                $existing_id = $this->check_image_exists_by_name($filename, $target_product_id);

                if ($existing_id) {
                    $images[] = [
                        'id' => $existing_id,
                        'src' => $image_url,
                        'position' => $index + 1
                    ];
                } else {
                    if ($uploaded_id = $this->upload_image($image_url)) {
                        $images[] = [
                            'id' => $uploaded_id,
                            'src' => $image_url,
                            'position' => $index + 1
                        ];
                    }
                }
            }
        }

        return $images;
    }

    public function prepare_variation_images($variation_id, $target_product_id = null)
    {
        $variation = wc_get_product($variation_id);
        if (!$variation) return [];

        $images = [];
        $all_urls = [];

        // Skupljamo sve URL-ove slika
        if ($image_id = $variation->get_image_id()) {
            if ($image_url = wp_get_attachment_url($image_id)) {
                $all_urls['main'] = $image_url;
            }
        }

        // ISPRAVKA: Inicijalizujemo gallery kao prazan niz
        $all_urls['gallery'] = [];

        $gallery_images = get_post_meta($variation_id, 'rtwpvg_images', true);
        if (!empty($gallery_images) && is_array($gallery_images)) {
            foreach ($gallery_images as $gallery_image_id) {
                if ($image_url = wp_get_attachment_url($gallery_image_id)) {
                    $all_urls['gallery'][] = $image_url;
                }
            }
        }

        if (empty($all_urls)) return [];

        // Batch pretraga za sve slike odjednom
        $all_filenames = [];
        if (isset($all_urls['main'])) {
            $all_filenames[] = basename(parse_url($all_urls['main'], PHP_URL_PATH));
        }
        // ISPRAVKA: Provera da gallery nije prazan
        if (!empty($all_urls['gallery'])) {
            foreach ($all_urls['gallery'] as $url) {
                $all_filenames[] = basename(parse_url($url, PHP_URL_PATH));
            }
        }

        // Ako nema slika, vraćamo prazan niz
        if (empty($all_filenames)) return [];

        $found_images = $this->batch_search_images($all_filenames, $target_product_id);
        // Procesiramo glavnu sliku
        if (isset($all_urls['main'])) {
            $filename = basename(parse_url($all_urls['main'], PHP_URL_PATH));
            if (isset($found_images[$filename])) {
                $images['main'] = $found_images[$filename];
            } else {
                if ($uploaded_id = $this->upload_image($all_urls['main'])) {
                    $images['main'] = $uploaded_id;
                }
            }
        }

        // Procesiramo galeriju
        // Procesiramo galeriju
        if (!empty($all_urls['gallery'])) {
            $gallery_ids = [];
            $urls_to_upload = [];

            // Prvo dodeli pronađene slike
            foreach ($all_urls['gallery'] as $gallery_url) {
                $filename = basename(parse_url($gallery_url, PHP_URL_PATH));
                if (isset($found_images[$filename])) {
                    $gallery_ids[] = $found_images[$filename];
                } else {
                    $urls_to_upload[] = $gallery_url;
                }
            }

            // Batch upload za sve koje nije pronašao
            if (!empty($urls_to_upload)) {
                $batch_uploaded = $this->batch_upload_images($urls_to_upload);
                foreach ($batch_uploaded as $uploaded_id) {
                    if ($uploaded_id) {
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
