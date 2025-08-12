<?php

namespace PicPilot\Restore;

use WP_Query;
use PicPilot\Logger;

class ContentUpdater {
    public static function replace_image_urls(int $attachment_id, string $old_url, string $new_url): void {
        global $wpdb;
        $updated_posts = 0;
        $updated_meta = 0;

        // Search and replace in post_content
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        foreach ($posts as $post) {
            $updated_content = str_replace($old_url, $new_url, $post->post_content);
            if ($updated_content !== $post->post_content) {
                wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $updated_content,
                ]);
                $updated_posts++;
            }
        }

        // Search and replace in postmeta
        $meta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        foreach ($meta as $row) {
            $value = maybe_unserialize($row->meta_value);
            if (!is_array($value)) {
                $decoded_json = json_decode($row->meta_value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded_json;
                }
            }

            $original = $value;
            $updated = self::deep_replace_url($value, $old_url, $new_url);
            if ($updated !== $original) {
                update_post_meta($row->post_id, $row->meta_key, $updated);
                $updated_meta++;
            }
        }

        if ($updated_posts || $updated_meta) {
            Logger::log("🪄 Updated image references: {$updated_posts} posts, {$updated_meta} meta entries.");
        }
    }

    private static function deep_replace_url($data, string $old, string $new) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::deep_replace_url($value, $old, $new);
            }
            return $data;
        }

        if (is_string($data)) {
            return str_replace($old, $new, $data);
        }

        return $data;
    }
}
