<?php
/*
Plugin Name: Shopito Sync
Description: Sinhronizacija proizvoda između dva woocommerce sajta
Version: 1.1.3
Author: Ilija Velemirov s7Code&Design
Text Domain: shopito-sync
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

// Definišemo konstante
define('SHOPITO_SYNC_VERSION', '1.1.2');
define('SHOPITO_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPITO_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader za klase
spl_autoload_register(function ($class) {
    $prefix = 'Shopito_Sync\\';
    $base_dir = SHOPITO_SYNC_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
function shopito_sync_check_version()
{
    if (get_option('shopito_sync_version') != SHOPITO_SYNC_VERSION) {
        // Izvršite potrebne nadogradnje
        update_option('shopito_sync_version', SHOPITO_SYNC_VERSION);
    }
}
add_action('plugins_loaded', 'shopito_sync_check_version', 5);

function shopito_sync_init()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>Shopito Sync zahteva WooCommerce plugin.</p></div>';
        });
        return;
    }

    // Čekamo da se WooCommerce potpuno učita
    add_action('woocommerce_init', function () {
        new Shopito_Sync\Admin();
        new Shopito_Sync\Product_Sync();
        new Shopito_Sync\Settings();
    });
}

add_action('after_setup_theme', 'shopito_sync_init');
register_deactivation_hook(__FILE__, 'shopito_sync_deactivate');

function shopito_sync_deactivate()
{
    // Brisanje opcija
    delete_option('shopito_sync_settings');

    // Brisanje meta podataka proizvoda
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_shopito_synced', '_synced_product_id', '_last_sync_date')");
}
