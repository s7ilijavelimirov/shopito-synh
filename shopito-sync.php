<?php
/*
Plugin Name: Shopito Sync
Description: Sinhronizacija proizvoda između dva woocommerce sajta
Version: 1.2.0
Author: Ilija Velemirov s7Code&Design
Text Domain: shopito-sync
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

// Definišemo konstante
define('SHOPITO_SYNC_VERSION', '1.2.0');
define('SHOPITO_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPITO_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SHOPITO_SYNC_LOGS_ENABLED', true);

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

        // Inicijalno podešavanje logovanja ako već nije podešeno
        $settings = get_option('shopito_sync_settings', []);
        if (!isset($settings['enable_logging'])) {
            $settings['enable_logging'] = 'yes';
            update_option('shopito_sync_settings', $settings);
        }
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
        // Prvo inicijalizujemo Logger za globalno korišćenje
        \Shopito_Sync\Logger::get_instance();

        // Inicijalizacija ostalih klasa
        new Shopito_Sync\Admin();
        new Shopito_Sync\Product_Sync();
        new Shopito_Sync\Settings();
    });
}

add_action('after_setup_theme', 'shopito_sync_init');

// Registrujemo CSS za admin
function shopito_sync_admin_styles()
{
    wp_enqueue_style(
        'shopito-sync-admin-core',
        SHOPITO_SYNC_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        SHOPITO_SYNC_VERSION
    );
}
add_action('admin_enqueue_scripts', 'shopito_sync_admin_styles');

// Funkcija koja se poziva pri deaktivaciji plugina
register_deactivation_hook(__FILE__, 'shopito_sync_deactivate');

function shopito_sync_deactivate()
{
    // Ne brišemo opcije i meta podatke pri deaktivaciji
    // To radimo samo pri brisanju
}

// Funkcija koja se poziva pri brisanju plugina
register_uninstall_hook(__FILE__, 'shopito_sync_uninstall');

function shopito_sync_uninstall()
{
    // Brisanje opcija
    delete_option('shopito_sync_settings');
    delete_option('shopito_sync_version');
    delete_option('shopito_sync_logs');

    // Brisanje meta podataka proizvoda
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_shopito_synced', '_synced_product_id', '_last_sync_date', '_last_stock_sync_date', '_synced_variation_id')");
}
