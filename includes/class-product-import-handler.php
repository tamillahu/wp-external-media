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
                    return current_user_can('manage_woocommerce');
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

            // Collect errors from create run results (row-level errors)
            if (!empty($res_create['failed'])) {
                foreach ($res_create['failed'] as $failed_item) {
                    if (is_wp_error($failed_item)) {
                        $results['errors'][] = array(
                            'code' => $failed_item->get_error_code(),
                            'message' => $failed_item->get_error_message(),
                            'data' => $failed_item->get_error_data()
                        );
                    } else {
                        // Sometimes it might just be the row data or ID, less likely but safe fallback
                        $results['errors'][] = array(
                            'code' => 'import_row_failed',
                            'message' => 'Row failed to import.',
                            'data' => $failed_item
                        );
                    }
                }
            }

            // Collect global errors from create run
            if (method_exists($importer_create, 'get_errors')) {
                foreach ($importer_create->get_errors() as $error) {
                    if (is_wp_error($error)) {
                        $results['errors'][] = array(
                            'code' => $error->get_error_code(),
                            'message' => $error->get_error_message(),
                            'data' => $error->get_error_data()
                        );
                    }
                }
            }

            // -- Run 2: Update --
            // We reuse the same file.
            $importer_update = new WC_Product_CSV_Importer($temp_file, $params_update);
            $res_update = $importer_update->import();

            $results['updated'] += count(isset($res_update['updated']) ? $res_update['updated'] : array());
            $results['failed'] += count(isset($res_update['failed']) ? $res_update['failed'] : array());

            // Collect errors from update run results
            if (!empty($res_update['failed'])) {
                foreach ($res_update['failed'] as $failed_item) {
                    if (is_wp_error($failed_item)) {
                        $results['errors'][] = array(
                            'code' => $failed_item->get_error_code(),
                            'message' => $failed_item->get_error_message(),
                            'data' => $failed_item->get_error_data()
                        );
                    } else {
                        $results['errors'][] = array(
                            'code' => 'import_row_failed',
                            'message' => 'Row failed to update.',
                            'data' => $failed_item
                        );
                    }
                }
            }

            // Collect global errors from update run
            if (method_exists($importer_update, 'get_errors')) {
                foreach ($importer_update->get_errors() as $error) {
                    if (is_wp_error($error)) {
                        $results['errors'][] = array(
                            'code' => $error->get_error_code(),
                            'message' => $error->get_error_message(),
                            'data' => $error->get_error_data()
                        );
                    }
                }
            }

            return $results;

        } catch (Exception $e) {
            return new WP_Error('import_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Helper to write content to a temporary CSV file in the uploads directory.
     * This mimics the behaviour that is known to work for raw body imports.
     *
     * @param string $content The CSV content.
     * @return string|WP_Error The path to the temporary file or WP_Error on failure.
     */
    private function create_temp_csv_file($content)
    {
        if (empty($content)) {
            return new WP_Error('no_data', 'No CSV data received.', array('status' => 400));
        }

        $upload_dir = wp_upload_dir();
        // Unique filename with .csv extension
        $temp_file_base = $upload_dir['basedir'] . '/wc_import_' . uniqid();
        $temp_file = $temp_file_base . '.csv';

        if (file_put_contents($temp_file, $content) === false) {
            return new WP_Error('file_error', 'Could not save temporary CSV file.', array('status' => 500));
        }

        return $temp_file;
    }

    public function import_products_csv($request)
    {
        // Check if WooCommerce is installed/active by checking for key class
        if (!class_exists('WC_Product_CSV_Importer')) {
            // Try to include if possible, but usually if class missing, plugin inactive.
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
        $csv_content = '';

        if (!empty($files['file'])) {
            // Multipart: Read content from the uploaded file
            $csv_content = file_get_contents($files['file']['tmp_name']);
        } else {
            // Raw Body
            $csv_content = $request->get_body();
        }

        // Create temp file using the unified helper
        $temp_file = $this->create_temp_csv_file($csv_content);

        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // Run the import
        $results = $this->do_import_products_csv($temp_file);

        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }

        return $results;
    }
}
