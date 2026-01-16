<?php
/**
 * Product Import Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Import_Handler
{
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route(
            'external-media/v1',
            '/import-products',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'import_products_csv'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            )
        );
    }

    public function do_import_products_csv($temp_file)
    {
        $mapping = array(
            'type' => 'type',
            'sku' => 'sku',
            'name' => 'name',
            'published' => 'published',
            'featured' => 'featured',
            'visibility' => 'visibility',
            'short_description' => 'short_description',
            'description' => 'description',
            'regular_price' => 'regular_price',
            'stock' => 'stock_quantity',
            'manage_stock' => 'manage_stock',
            'stock_status' => 'stock_status',
            'categories' => 'category_ids',
            'images' => 'images',
        );

        $params_create = array(
            'lines' => -1,
            'update_existing' => false,
            'delimiter' => ',',
            'mapping' => $mapping,
            'parse' => true,
        );

        $params_update = array(
            'lines' => -1,
            'update_existing' => true,
            'delimiter' => ',',
            'mapping' => $mapping,
            'parse' => true,
        );

        $results = array(
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => array()
        );

        try {
            // -- Run 1: Create --

            // Use Anonymous Class to avoid "Class not found" issues during plugin load
            $importer_create = new WC_Product_CSV_Importer($temp_file, $params_create);

            $res_create = $importer_create->import();

            $results['created'] += count(isset($res_create['imported']) ? $res_create['imported'] : array());
            $results['failed'] += count(isset($res_create['failed']) ? $res_create['failed'] : array());
            $results['skipped'] += count(isset($res_create['skipped']) ? $res_create['skipped'] : array());

            // -- Run 2: Update --
            // We reuse the same file.
            $importer_update = new WC_Product_CSV_Importer($temp_file, $params_update);
            $res_update = $importer_update->import();

            $results['updated'] += count(isset($res_update['updated']) ? $res_update['updated'] : array());
            $results['failed'] += count(isset($res_update['failed']) ? $res_update['failed'] : array());

            return $results;

        } catch (Exception $e) {
            return new WP_Error('import_error', $e->getMessage(), array('status' => 500));
        }
    }

    public function import_products_csv($request)
    {
        // Check if WooCommerce is installed/active by checking for key class
        if (!class_exists('WC_Product_CSV_Importer')) {
            // Try to include if possible, but usually if class missing, plugin inactive.
            // Try to find the file if we are in environment where it might be lazy loaded?
            // Usually WP loads active plugins. If not found, return error.
            if (!defined('WC_ABSPATH')) {
                return new WP_Error('woocommerce_missing', 'WooCommerce is not active.', array('status' => 501));
            }
            include_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
        }

        if (!class_exists('WC_Product_CSV_Importer')) {
            return new WP_Error('woocommerce_importer_missing', 'WooCommerce CSV Importer class not found.', array('status' => 500));
        }


        // Get file data
        $files = $request->get_file_params();
        $temp_file = '';

        if (!empty($files['file'])) {
            $temp_file = $files['file']['tmp_name'];
        } else {
            // Fallback: Read raw body and save to temp file
            $body = $request->get_body();
            if (empty($body)) {
                return new WP_Error('no_data', 'No CSV data received.', array('status' => 400));
            }
            // Use WP Uploads dir to avoid permission/restriction issues with /tmp
            $upload_dir = wp_upload_dir();
            $temp_file_base = $upload_dir['basedir'] . '/wc_import_' . uniqid();
            $temp_file = $temp_file_base . '.csv';
            file_put_contents($temp_file, $body);
        }

        $results = $this->do_import_products_csv($temp_file);

        // Clean up temp file if we created it manually from body
        if (empty($files['file']) && file_exists($temp_file)) {
            unlink($temp_file);
        }

        return $results;
    }
}
