<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Brisanje svih opcija
delete_option('shopito_sync_settings');
delete_option('shopito_sync_version');

// Brisanje meta podataka
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_shopito_synced', '_synced_product_id', '_last_sync_date')");