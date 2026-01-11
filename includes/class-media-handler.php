<?php
/**
 * Media Handler Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class External_Media_Handler
{

    public function init()
    {
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'filter_attachment_image_src'), 10, 4);
        add_filter('wp_calculate_image_srcset', array($this, 'filter_image_srcset'), 10, 5);
    }

    /**
     * Filter the attachment URL to return the external full URL.
     */
    public function filter_attachment_url($url, $post_id)
    {
        if ($this->is_external_media($post_id)) {
            $external_urls = get_post_meta($post_id, '_external_urls', true);
            if (!empty($external_urls) && is_array($external_urls)) {
                // Default to 'full' or the first available
                if (isset($external_urls['full'])) {
                    return $external_urls['full'];
                }
                $first = reset($external_urls);
                return $first;
            }
        }
        return $url;
    }

    /**
     * Filter the attachment image src to return the appropriate size variant.
     */
    public function filter_attachment_image_src($image, $attachment_id, $size, $icon)
    {
        if ($this->is_external_media($attachment_id)) {
            $external_urls = get_post_meta($attachment_id, '_external_urls', true);

            if (empty($external_urls) || !is_array($external_urls)) {
                return $image;
            }

            $target_url = '';

            // Handle size mapping
            // WP sizes: thumbnail, medium, medium_large, large, full
            // External keys assumed to match or close enough
            if (is_array($size)) {
                // Custom size array provided, map to closest standard or just use full
                $target_url = isset($external_urls['full']) ? $external_urls['full'] : reset($external_urls);
            } elseif (isset($external_urls[$size])) {
                $target_url = $external_urls[$size];
            } else {
                // Fallback to full if specific size not found
                $target_url = isset($external_urls['full']) ? $external_urls['full'] : reset($external_urls);
            }

            if ($target_url) {
                // Return [url, width, height, is_intermediate]
                // Since we don't have dimensions, we return 0, 0
                return array($target_url, 0, 0, false);
            }
        }
        return $image;
    }

    /**
     * Disable srcset for external images as we might not have all defined WP sizes
     * and don't want to break the layout with broken guesses.
     * Or we can try to rebuild it if we have all keys.
     * For now, returning false disables srcset, ensuring sanity.
     */
    public function filter_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
    {
        if ($this->is_external_media($attachment_id)) {
            return false;
        }
        return $sources;
    }

    private function is_external_media($post_id)
    {
        return '1' === get_post_meta($post_id, '_is_external_media', true);
    }
}
