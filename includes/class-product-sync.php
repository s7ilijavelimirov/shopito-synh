<?php

namespace Shopito_Sync;

use DateTime;
use Exception;

class Product_Sync
{
    private $api_handler;
    private $logger;

    public function __construct()
    {
        // Dobavljamo API Handler iz globalne funkcije
        $handlers = shopito_sync_initialize_handlers();
        $this->api_handler = $handlers['api_handler'];
        $this->logger = Logger::get_instance();

        add_action('wp_ajax_sync_to_ba', array($this, 'handle_sync_request'));
        add_action('wp_ajax_sync_stock_to_ba', array($this, 'handle_stock_sync_request'));
        add_action('add_meta_boxes', array($this, 'add_sync_meta_box'));
    }

    public function add_sync_meta_box()
    {
        add_meta_box(
            'shopito_sync_box',
            'Shopito.ba Sinhronizacija',
            array($this, 'render_sync_meta_box'),
            'product',
            'side',
            'high'
        );
    }

    public function render_sync_meta_box($post)
    {
        wp_nonce_field('shopito_sync_action', 'shopito_sync_nonce');
        $last_sync = get_post_meta($post->ID, '_last_sync_date', true);
        $last_stock_sync = get_post_meta($post->ID, '_last_stock_sync_date', true);
        $product = wc_get_product($post->ID);
        $is_variable = $product && $product->get_type() === 'variable';
?>
        <div class="shopito-sync-container">
            <button type="button" class="button shopito-sync-now"
                data-product-id="<?php echo esc_attr($post->ID); ?>"
                data-is-variable="<?php echo $is_variable ? 'true' : 'false'; ?>">
                Sinhroniši odmah
                <span class="spinner"></span>
            </button>
            <div class="sync-options" style="margin: 10px 0;">
                <label>
                    <input type="checkbox" id="skip-images" class="skip-images-option">
                    Preskoči upload slika (brža sinhronizacija)
                </label>
            </div>
            <!-- Dugme za sinhronizaciju samo stanja -->
            <button type="button" class="button shopito-sync-stock"
                data-product-id="<?php echo esc_attr($post->ID); ?>"
                data-is-variable="<?php echo $is_variable ? 'true' : 'false'; ?>">
                Sinhroniši stanje
                <span class="spinner"></span>
            </button>
            <div class="sync-status"></div>
            <div class="sync-progress-container" style="display:none;">
                <div class="sync-step" data-step="product">
                    <span class="step-icon dashicons dashicons-tag"></span>
                    <span class="step-text">Kreiranje proizvoda...</span>
                    <span class="step-status"></span>
                </div>
                <div class="sync-step" data-step="images">
                    <span class="step-icon dashicons dashicons-format-image"></span>
                    <span class="step-text">Upload slika...</span>
                    <span class="step-status"></span>
                </div>
                <?php if ($is_variable): ?>
                    <div class="sync-step" data-step="variations">
                        <span class="step-icon dashicons dashicons-update"></span>
                        <span class="step-text">Kreiranje varijacija...</span>
                        <span class="step-status"></span>
                    </div>
                <?php endif; ?>
                <div class="sync-step" data-step="prices">
                    <span class="step-icon dashicons dashicons-money-alt"></span>
                    <span class="step-text">Konverzija cena...</span>
                    <span class="step-status"></span>
                </div>
                <!-- Korak za stanje -->
                <div class="sync-step" data-step="stock">
                    <span class="step-icon dashicons dashicons-archive"></span>
                    <span class="step-text">Ažuriranje stanja...</span>
                    <span class="step-status"></span>
                </div>
            </div>

            <!-- Informacije o sinhronizaciji -->
            <div class="sync-info-container">
                <?php if ($last_sync): ?>
                    <?php
                    $date = new DateTime($last_sync);
                    $formatted_date = $date->format('d.m.Y. H:i');
                    ?>
                    <div class="last-sync" title="Datum i vreme pune sinhronizacije">
                        <span class="sync-label">Poslednja puna sinhronizacija:</span>
                        <div class="sync-details">
                            <span class="sync-date"><?php echo $formatted_date; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($last_stock_sync): ?>
                    <?php
                    $stock_date = new DateTime($last_stock_sync);
                    $stock_formatted_date = $stock_date->format('d.m.Y. H:i');
                    ?>
                    <div class="last-stock-sync" title="Datum i vreme sinhronizacije stanja">
                        <span class="sync-label">Poslednja sinhronizacija stanja:</span>
                        <div class="sync-details">
                            <span class="sync-date"><?php echo $stock_formatted_date; ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
<?php
    }

    public function handle_stock_sync_request()
    {
        check_ajax_referer('shopito_sync_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error('Neispravan ID proizvoda');
            return;
        }

        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                wp_send_json_error('Proizvod nije pronađen');
                return;
            }

            $this->logger->info("Započeta sinhronizacija stanja proizvoda", [
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'product_sku' => $product->get_sku()
            ]);

            $result = $this->api_handler->sync_product_stock($product_id);

            if (is_wp_error($result)) {
                $this->logger->error("Greška pri sinhronizaciji stanja", [
                    'product_id' => $product_id,
                    'error' => $result->get_error_message()
                ]);
                wp_send_json_error($result->get_error_message());
                return;
            }

            if (!isset($result['success']) || !$result['success']) {
                $this->logger->error("Sinhronizacija stanja nije uspela", ['product_id' => $product_id]);
                wp_send_json_error('Sinhronizacija stanja nije uspela');
                return;
            }

            $current_time = current_time('mysql');
            update_post_meta($product_id, '_last_stock_sync_date', $current_time);

            $this->logger->success("Stanje proizvoda uspešno sinhronizovano", [
                'product_id' => $product_id,
                'sync_date' => $current_time
            ]);

            $response = [
                'message' => 'Stanje proizvoda je uspešno sinhronizovano',
                'last_sync' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($current_time)),
                'steps' => isset($result['steps']) ? $result['steps'] : []
            ];

            wp_send_json_success($response);
        } catch (Exception $e) {
            $this->logger->error("Izuzetak pri sinhronizaciji stanja", [
                'product_id' => $product_id,
                'exception' => $e->getMessage()
            ]);
            wp_send_json_error('Greška: ' . $e->getMessage());
        }
    }

    public function handle_sync_request()
    {
        check_ajax_referer('shopito_sync_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $skip_images = isset($_POST['skip_images']) && $_POST['skip_images'] === 'true';

        if (!$product_id) {
            wp_send_json_error('Neispravan ID proizvoda');
            return;
        }

        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                wp_send_json_error('Proizvod nije pronađen');
                return;
            }

            $this->logger->info("Započeta puna sinhronizacija proizvoda", [
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'product_sku' => $product->get_sku(),
                'skip_images' => $skip_images
            ]);

            $result = $this->api_handler->sync_product($product_id, $skip_images);

            if (is_wp_error($result)) {
                $this->logger->error("Greška pri punoj sinhronizaciji", [
                    'product_id' => $product_id,
                    'error' => $result->get_error_message()
                ]);
                wp_send_json_error($result->get_error_message());
                return;
            }

            if (!isset($result['success']) || !$result['success']) {
                $this->logger->error("Puna sinhronizacija nije uspela", ['product_id' => $product_id]);
                wp_send_json_error('Sinhronizacija nije uspela');
                return;
            }

            $current_time = current_time('mysql');
            update_post_meta($product_id, '_shopito_synced', $current_time);
            update_post_meta($product_id, '_last_sync_date', $current_time);

            $this->logger->success("Proizvod uspešno sinhronizovan", [
                'product_id' => $product_id,
                'action' => $result['action'],
                'sync_date' => $current_time
            ]);

            $steps = isset($result['steps']) ? $result['steps'] : [];
            $default_steps = [
                [
                    'name' => 'product',
                    'status' => 'completed',
                    'message' => 'Proizvod kreiran/ažuriran'
                ],
                [
                    'name' => 'prices',
                    'status' => 'completed',
                    'message' => 'Cene konvertovane'
                ]
            ];

            $steps = array_merge($steps, $default_steps);

            $response = [
                'message' => $result['action'] === 'updated' ?
                    'Proizvod je uspešno ažuriran' :
                    'Proizvod je uspešno sinhronizovan',
                'last_sync' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($current_time)),
                'steps' => $steps
            ];

            wp_send_json_success($response);
        } catch (Exception $e) {
            $this->logger->error("Izuzetak pri punoj sinhronizaciji", [
                'product_id' => $product_id,
                'exception' => $e->getMessage()
            ]);
            wp_send_json_error('Greška: ' . $e->getMessage());
        }
    }
}
