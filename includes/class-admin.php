<?php

namespace Shopito_Sync;

if (!defined('ABSPATH')) {
    exit;
}

class Admin
{
    public function __construct()
    {
        // Registrujemo admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Dodajemo admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Učitavanje admin CSS i JS fajlova
     */
    public function enqueue_admin_assets($hook)
    {
        if ($hook == 'post-new.php' || $hook == 'post.php' || strpos($hook, 'shopito-sync') !== false) {
            wp_enqueue_style(
                'shopito-sync-admin',
                SHOPITO_SYNC_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                SHOPITO_SYNC_VERSION
            );

            wp_enqueue_script(
                'shopito-sync-admin',
                SHOPITO_SYNC_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                SHOPITO_SYNC_VERSION,
                true
            );

            // Kombinujemo sve potrebne lokalizovane podatke
            wp_localize_script('shopito-sync-admin', 'shopitoSync', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('shopito_sync_nonce'),
                'test_nonce' => wp_create_nonce('test_shopito_connection'),
                'syncing_text' => __('Sinhronizacija u toku...', 'shopito-sync'),
                'success_text' => __('Uspešno sinhronizovano', 'shopito-sync'),
                'error_text' => __('Greška pri sinhronizaciji', 'shopito-sync'),
                'testing_text' => __('Testiranje konekcije...', 'shopito-sync')
            ));
            wp_add_inline_style('shopito-sync-admin', '
            .environment-label {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
                margin-left: 10px;
                vertical-align: middle;
            }
            .environment-label.production {
                background-color: #dc3545;
                color: white;
            }
            .environment-label.test {
                background-color: #ffc107;
                color: black;
            }
            .environment-label.local {
                background-color: #28a745;
                color: white;
            }
        ');
        }
    }

    /**
     * Prikaz admin obaveštenja
     */
    public function admin_notices()
    {
        $settings = get_option('shopito_sync_settings');

        // Proveravamo da li su postavljena osnovna podešavanja
        if (!isset($settings['target_url']) || empty($settings['target_url'])) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>' . __('Shopito Sync: Molimo vas da podesite URL ciljnog sajta u podešavanjima.', 'shopito-sync') . ' ';
            echo '<a href="' . admin_url('admin.php?page=shopito-sync') . '">' . __('Podešavanja', 'shopito-sync') . '</a></p>';
            echo '</div>';
        }
    }
}
