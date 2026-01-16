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
            $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
            if (empty($external_urls) || !is_array($external_urls)) {
                return $image;
            }
            $target_url = '';
            $width = 0;
            $height = 0;
            // Handle string size (e.g. 'full', 'large')
            if (!is_array($size)) {
                // Try to find exact match
                if (isset($external_urls[$size])) {
                    $target_url = $external_urls[$size];

                    // Get dimensions
                    if ($size === 'full') {
                        $width = isset($metadata['width']) ? $metadata['width'] : 0;
                        $height = isset($metadata['height']) ? $metadata['height'] : 0;
                    } elseif (isset($metadata['sizes'][$size])) {
                        $width = isset($metadata['sizes'][$size]['width']) ? $metadata['sizes'][$size]['width'] : 0;
                        $height = isset($metadata['sizes'][$size]['height']) ? $metadata['sizes'][$size]['height'] : 0;
                    }
                } else {
                    // Fallback to large or full if missing
                    // Also check if 'full' maps to implicit original
                    if ($size === 'full' && isset($external_urls['full'])) {
                        $target_url = $external_urls['full'];
                        $width = isset($metadata['width']) ? $metadata['width'] : 0;
                        $height = isset($metadata['height']) ? $metadata['height'] : 0;
                    } else {
                        $target_url = isset($external_urls['large']) ? $external_urls['large'] : reset($external_urls);
                        // If falling back, we might not have correct dimensions, but try 'large'
                        if (isset($metadata['sizes']['large'])) {
                            $width = $metadata['sizes']['large']['width'];
                            $height = $metadata['sizes']['large']['height'];
                        }
                    }
                }
            }
            if ($target_url) {
                return array($target_url, $width, $height, $size !== 'full');
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
