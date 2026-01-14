<?php
/**
 * API Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class External_Media_API_Handler
{

    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route(
            'external-media/v1',
            '/import',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_import'),
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            )
        );

        register_rest_route(
            'external-media/v1',
            '/image-sizes',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_registered_image_sizes'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_import($request)
    {
        // Attempt to extend timeout
        @set_time_limit(0);

        $params = $request->get_json_params();

        if (!is_array($params)) {
            return new WP_Error('invalid_data', 'Invalid JSON data provided.', array('status' => 400));
        }

        // Enable Maintenance Mode
        $this->enable_maintenance_mode();

        // Register cleanup on shutdown
        register_shutdown_function(array($this, 'disable_maintenance_mode'));

        try {
            $results = $this->process_import($params);
        } catch (Exception $e) {
            // Maintenance mode will be disabled by shutdown function
            return new WP_Error('import_failed', $e->getMessage(), array('status' => 500));
        }

        // Explicit cleanup
        $this->disable_maintenance_mode();

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Import completed successfully.',
            'results' => $results,
        ));
    }

    private function process_import($data)
    {
        // 1. Get all existing external media (ID => Post ID)
        $existing_media = $this->get_existing_external_media();
        $processed_ids = array();

        $results = array(
            'created' => array(),
            'updated' => array(),
            'deleted' => array(),
            'unchanged' => array(),
        );

        foreach ($data as $item) {
            if (empty($item['id']) || empty($item['urls'])) {
                continue;
            }

            $external_id = (string) $item['id'];
            $processed_ids[] = $external_id;

            $post_title = isset($item['title']) ? $item['title'] : 'External Media ' . $external_id;
            $mime_type = isset($item['mime_type']) ? $item['mime_type'] : 'application/octet-stream';

            $meta_input = array(
                '_is_external_media' => '1',
                '_external_id' => $external_id,
                '_external_urls' => $item['urls'], // Associative array of sizes
                '_external_metadata' => isset($item['metadata']) ? $item['metadata'] : array(),
            );

            if (isset($existing_media[$external_id])) {
                // Check if update is needed
                $post_id = $existing_media[$external_id];

                if ($this->has_changes($post_id, $post_title, $meta_input)) {
                    // Update existing
                    $post_data = array(
                        'ID' => $post_id,
                        'post_title' => $post_title,
                        'post_mime_type' => $mime_type,
                    );
                    wp_update_post($post_data);

                    foreach ($meta_input as $key => $value) {
                        update_post_meta($post_id, $key, $value);
                    }
                    $results['updated'][] = $external_id;
                } else {
                    $results['unchanged'][] = $external_id;
                }
            } else {
                // Create new
                $post_data = array(
                    'post_title' => $post_title,
                    'post_mime_type' => $mime_type,
                    'post_status' => 'inherit',
                    'post_type' => 'attachment',
                );

                $post_id = wp_insert_post($post_data);
                if (!is_wp_error($post_id)) {
                    foreach ($meta_input as $key => $value) {
                        update_post_meta($post_id, $key, $value);
                    }
                    $results['created'][] = $external_id;
                }
            }
        }

        // 2. Initial Deletion logic
        // Identify keys in $existing_media that are NOT in $processed_ids
        $orphaned_ids = array_diff(array_keys($existing_media), $processed_ids);

        foreach ($orphaned_ids as $external_id) {
            if (isset($existing_media[$external_id])) {
                wp_delete_post($existing_media[$external_id], true);
                $results['deleted'][] = $external_id;
            }
        }

        return $results;
    }

    private function has_changes($post_id, $new_title, $new_meta)
    {
        // Check Title
        $current_post = get_post($post_id);
        if ($current_post->post_title !== $new_title) {
            return true;
        }

        // Check Meta
        foreach ($new_meta as $key => $value) {
            if ($key === '_is_external_media' || $key === '_external_id') {
                continue; // These strictly match by definition of finding the post
            }
            $current_value = get_post_meta($post_id, $key, true);

            // Normalize for comparison (strict check usually works for assoc arrays in PHP if order matches)
            if ($current_value !== $value) {
                return true;
            }
        }

        return false;
    }

    private function get_existing_external_media()
    {
        global $wpdb;

        // Efficient query to map _external_id to post_ID
        $results = $wpdb->get_results("
			SELECT post_id, meta_value 
			FROM $wpdb->postmeta 
			WHERE meta_key = '_external_id' 
			AND post_id IN (
				SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_is_external_media' AND meta_value = '1'
			)
		");

        $map = array();
        foreach ($results as $row) {
            $map[$row->meta_value] = $row->post_id;
        }

        return $map;
    }

    private function enable_maintenance_mode()
    {
        $maintenance_file = ABSPATH . '.maintenance';
        $content = '<?php $upgrading = ' . time() . '; ?>';
        file_put_contents($maintenance_file, $content);
    }

    public function disable_maintenance_mode()
    {
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            unlink($maintenance_file);
        }
    }

    public function get_registered_image_sizes()
    {
        global $_wp_additional_image_sizes;
        $sizes = array();

        // Get standard WP sizes (thumbnail, medium, large)
        foreach (get_intermediate_image_sizes() as $_size) {
            if (in_array($_size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
                $sizes[$_size] = array(
                    'width' => get_option("{$_size}_size_w"),
                    'height' => get_option("{$_size}_size_h"),
                    'crop' => (bool) get_option("{$_size}_crop"),
                );
            } elseif (isset($_wp_additional_image_sizes[$_size])) {
                // Get sizes registered by plugins (WooCommerce, Swatches, etc.)
                $sizes[$_size] = array(
                    'width' => $_wp_additional_image_sizes[$_size]['width'],
                    'height' => $_wp_additional_image_sizes[$_size]['height'],
                    'crop' => $_wp_additional_image_sizes[$_size]['crop'],
                );
            }
        }

        return $sizes;
    }
}
