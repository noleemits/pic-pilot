<?php

namespace PicPilot\Backup;

use PicPilot\Logger;

defined('ABSPATH') || exit;

/**
 * Universal URL replacement system for content restoration
 * Handles URL updates across WordPress core, page builders, and third-party systems
 */
class ContentReplacer {
    
    /**
     * Replace image URLs across all content
     */
    public static function replace_image_urls(string $old_url, string $new_url, int $attachment_id): void {
        if ($old_url === $new_url) {
            Logger::log("⏩ URLs are identical, skipping replacement");
            return;
        }
        
        Logger::log("🔄 Starting URL replacement: $old_url → $new_url");
        
        $replacements_made = 0;
        
        // Layer 1: WordPress core content
        $replacements_made += self::replace_in_posts($old_url, $new_url);
        $replacements_made += self::replace_in_meta($old_url, $new_url);
        $replacements_made += self::replace_in_widgets($old_url, $new_url);
        
        // Layer 2: Page builders
        $replacements_made += self::replace_in_elementor($old_url, $new_url);
        $replacements_made += self::replace_in_gutenberg($old_url, $new_url);
        
        // Layer 3: Third-party integrations
        $replacements_made += self::replace_in_customizer($old_url, $new_url);
        
        // Layer 4: Handle thumbnail variations
        $replacements_made += self::replace_thumbnail_urls($old_url, $new_url, $attachment_id);
        
        // Clear caches
        self::clear_caches($attachment_id);
        
        Logger::log("✅ URL replacement completed: $replacements_made total replacements made");
    }
    
    /**
     * Replace URLs in post content and excerpts
     */
    private static function replace_in_posts(string $old_url, string $new_url): int {
        global $wpdb;
        
        $replacements = 0;
        
        // Replace in post_content
        $content_results = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        if ($content_results !== false) {
            $replacements += $content_results;
            Logger::log("📝 Replaced URLs in $content_results post content entries");
        }
        
        // Replace in post_excerpt
        $excerpt_results = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(post_excerpt, %s, %s) WHERE post_excerpt LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        if ($excerpt_results !== false) {
            $replacements += $excerpt_results;
            Logger::log("📝 Replaced URLs in $excerpt_results post excerpt entries");
        }
        
        return $replacements;
    }
    
    /**
     * Replace URLs in post meta fields
     */
    private static function replace_in_meta(string $old_url, string $new_url): int {
        global $wpdb;
        
        $results = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        if ($results !== false && $results > 0) {
            Logger::log("📝 Replaced URLs in $results post meta entries");
            return $results;
        }
        
        return 0;
    }
    
    /**
     * Replace URLs in widget content
     */
    private static function replace_in_widgets(string $old_url, string $new_url): int {
        global $wpdb;
        
        $replacements = 0;
        
        // Replace in options table (widgets are stored there)
        $results = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_name LIKE 'widget_%' AND option_value LIKE %s",
                $old_url,  
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        if ($results !== false && $results > 0) {
            $replacements += $results;
            Logger::log("🔧 Replaced URLs in $results widget entries");
        }
        
        return $replacements;
    }
    
    /**
     * Replace URLs in Elementor page builder data
     */
    private static function replace_in_elementor(string $old_url, string $new_url): int {
        global $wpdb;
        
        $replacements = 0;
        
        // Elementor stores data in _elementor_data meta field
        $elementor_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        foreach ($elementor_posts as $post) {
            $elementor_data = $post->meta_value;
            $updated_data = str_replace($old_url, $new_url, $elementor_data);
            
            if ($updated_data !== $elementor_data) {
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => $updated_data],
                    ['meta_id' => $post->meta_id]
                );
                $replacements++;
                
                // Clear Elementor cache for this post
                delete_post_meta($post->post_id, '_elementor_css');
                delete_post_meta($post->post_id, '_elementor_css_time');
            }
        }
        
        if ($replacements > 0) {
            Logger::log("🎨 Replaced URLs in $replacements Elementor entries");
        }
        
        return $replacements;
    }
    
    /**
     * Replace URLs in Gutenberg block content
     */
    private static function replace_in_gutenberg(string $old_url, string $new_url): int {
        global $wpdb;
        
        $replacements = 0;
        
        // Gutenberg blocks are in post_content, but we need to handle JSON attributes
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_content LIKE '%wp:image%'",
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        foreach ($posts as $post) {
            $content = $post->post_content;
            $updated_content = str_replace($old_url, $new_url, $content);
            
            if ($updated_content !== $content) {
                $wpdb->update(
                    $wpdb->posts,
                    ['post_content' => $updated_content],
                    ['ID' => $post->ID]
                );
                $replacements++;
                
                // Clear post cache
                clean_post_cache($post->ID);
            }
        }
        
        if ($replacements > 0) {
            Logger::log("📱 Replaced URLs in $replacements Gutenberg entries");
        }
        
        return $replacements;
    }
    
    /**
     * Replace URLs in theme customizer settings
     */
    private static function replace_in_customizer(string $old_url, string $new_url): int {
        global $wpdb;
        
        $replacements = 0;
        
        // Check theme mods and customizer settings
        $customizer_options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_value FROM {$wpdb->options} WHERE (option_name LIKE 'theme_mods_%' OR option_name LIKE 'customizer_%') AND option_value LIKE %s",
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );
        
        foreach ($customizer_options as $option) {
            $value = maybe_unserialize($option->option_value);
            $updated_value = self::recursive_replace($value, $old_url, $new_url);
            
            if ($updated_value !== $value) {
                $wpdb->update(
                    $wpdb->options,
                    ['option_value' => maybe_serialize($updated_value)],
                    ['option_id' => $option->option_id]
                );
                $replacements++;
            }
        }
        
        if ($replacements > 0) {
            Logger::log("🎨 Replaced URLs in $replacements customizer entries");
        }
        
        return $replacements;
    }
    
    /**
     * Replace thumbnail URLs (different sizes)
     */
    private static function replace_thumbnail_urls(string $old_url, string $new_url, int $attachment_id): int {
        $replacements = 0;
        
        // Get thumbnail sizes for this attachment
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return 0;
        }
        
        // Generate old and new thumbnail URLs
        $old_base = pathinfo($old_url, PATHINFO_DIRNAME) . '/' . pathinfo($old_url, PATHINFO_FILENAME);
        $old_ext = pathinfo($old_url, PATHINFO_EXTENSION);
        
        $new_base = pathinfo($new_url, PATHINFO_DIRNAME) . '/' . pathinfo($new_url, PATHINFO_FILENAME);
        $new_ext = pathinfo($new_url, PATHINFO_EXTENSION);
        
        foreach ($metadata['sizes'] as $size_name => $size_data) {
            $old_thumb_url = $old_base . '-' . $size_data['width'] . 'x' . $size_data['height'] . '.' . $old_ext;
            $new_thumb_url = $new_base . '-' . $size_data['width'] . 'x' . $size_data['height'] . '.' . $new_ext;
            
            // Replace thumbnail URLs in content
            $thumb_replacements = self::replace_in_posts($old_thumb_url, $new_thumb_url);
            $thumb_replacements += self::replace_in_meta($old_thumb_url, $new_thumb_url);
            
            $replacements += $thumb_replacements;
        }
        
        if ($replacements > 0) {
            Logger::log("🖼️ Replaced $replacements thumbnail URL variations");
        }
        
        return $replacements;
    }
    
    /**
     * Recursively replace URLs in arrays and objects
     */
    private static function recursive_replace($data, string $old_url, string $new_url) {
        if (is_string($data)) {
            return str_replace($old_url, $new_url, $data);
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::recursive_replace($value, $old_url, $new_url);
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = self::recursive_replace($value, $old_url, $new_url);
            }
        }
        
        return $data;
    }
    
    /**
     * Clear various caches after URL replacement
     */
    private static function clear_caches(int $attachment_id): void {
        // Clear WordPress core caches
        clean_post_cache($attachment_id);
        wp_cache_delete($attachment_id, 'post_meta');
        
        // Clear object cache
        wp_cache_flush();
        
        // Clear Elementor cache if available
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
        
        // Clear common caching plugins
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        
        Logger::log("🧹 Cleared caches after URL replacement");
    }
}