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
            $this->process_import($params);
        } catch (Exception $e) {
            // Maintenance mode will be disabled by shutdown function
            return new WP_Error('import_failed', $e->getMessage(), array('status' => 500));
        }

        // Explicit cleanup
        $this->disable_maintenance_mode();

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Import completed successfully.',
        ));
    }

    private function process_import($data)
    {
        // 1. Get all existing external media (ID => Post ID)
        $existing_media = $this->get_existing_external_media();
        $processed_ids = array();

        foreach ($data as $item) {
            if (empty($item['id']) || empty($item['urls'])) {
                continue;
            }

            $external_id = (string) $item['id'];
            $processed_ids[] = $external_id;

            $post_data = array(
                'post_title' => isset($item['title']) ? $item['title'] : 'External Media ' . $external_id,
                'post_mime_type' => isset($item['mime_type']) ? $item['mime_type'] : 'application/octet-stream',
                'post_status' => 'inherit',
                'post_type' => 'attachment',
            );

            $meta_input = array(
                '_is_external_media' => '1',
                '_external_id' => $external_id,
                '_external_urls' => $item['urls'], // Associative array of sizes
                '_external_metadata' => isset($item['metadata']) ? $item['metadata'] : array(),
            );

            if (isset($existing_media[$external_id])) {
                // Update existing
                $post_id = $existing_media[$external_id];
                $post_data['ID'] = $post_id;
                wp_update_post($post_data);

                foreach ($meta_input as $key => $value) {
                    update_post_meta($post_id, $key, $value);
                }
            } else {
                // Create new
                $post_id = wp_insert_post($post_data);
                if (!is_wp_error($post_id)) {
                    foreach ($meta_input as $key => $value) {
                        update_post_meta($post_id, $key, $value);
                    }
                }
            }
        }

        // 2. Initial Deletion logic
        // Identify keys in $existing_media that are NOT in $processed_ids
        $orphaned_ids = array_diff(array_keys($existing_media), $processed_ids);

        foreach ($orphaned_ids as $external_id) {
            if (isset($existing_media[$external_id])) {
                wp_delete_post($existing_media[$external_id], true);
            }
        }
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
}
