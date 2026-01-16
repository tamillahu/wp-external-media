<?php
/**
 * Plugin Name: External Media for WordPress
 * Description: Import and handle external media files via API, storing only metadata and serving from source URLs.
 * Version: 0.2.0
 * Author: Balázs Grill & Antigravity
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.8.3
 * Tested up to: 6.9
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EXTERNAL_MEDIA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EXTERNAL_MEDIA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EXTERNAL_MEDIA_PLUGIN_DIR . 'includes/class-api-handler.php';
require_once EXTERNAL_MEDIA_PLUGIN_DIR . 'includes/class-media-handler.php';

require_once EXTERNAL_MEDIA_PLUGIN_DIR . 'includes/class-product-import-handler.php';

// Initialize the plugin
function external_media_init()
{
    $api_handler = new External_Media_API_Handler();
    $api_handler->init();

    $media_handler = new External_Media_Handler();
    $media_handler->init();

    $product_import_handler = new Product_Import_Handler();
    $product_import_handler->init();
}
add_action('plugins_loaded', 'external_media_init');
