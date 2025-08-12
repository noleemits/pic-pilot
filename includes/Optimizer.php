<?php

namespace PicPilot;

use PicPilot\Compressor\EngineRouter;
use PicPilot\Utils;
use PicPilot\Settings;
use PicPilot\Compressor\CompressorInterface;
use PicPilot\Backup\BackupService;
use PicPilot\Resize;
use PicPilot\Compressor\Local\LocalJpegCompressor;

class Optimizer {
    /**
     * Optimize an attachment by ID and return results.
     *
     * @param int $attachment_id
     * @param bool $handle_resize Whether to handle resize during optimization (default: true for manual optimization, false for upload processing)
     * @return array Result data including success, engine, saved, and details.
     */
    public static function optimize_attachment(int $attachment_id, bool $handle_resize = true): array {
        $path = get_attached_file($attachment_id);
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'File not found.'];
        }

        $mime = mime_content_type($path);
        if (strpos($mime, 'image/') !== 0) {
            return ['success' => false, 'error' => 'Not an image.'];
        }

        // Optional: Backup original image
        if (Settings::is_enabled('enable_backup')) {
            BackupService::create_backup($attachment_id, true);
        }

        // Handle resize during optimization if enabled and requested
        if ($handle_resize) {
            $resize_enabled = Settings::is_enabled('resize_during_optimization');
            Logger::log("🔧 Resize during optimization setting: " . ($resize_enabled ? 'enabled' : 'disabled'));
            
            if ($resize_enabled) {
                $path = self::handle_optimization_resize($attachment_id, $path);
            }
        } else {
            Logger::log("🔧 Skipping optimization resize (handled by upload processor)");
        }

        // Resolve the engine dynamically
        $compressor = EngineRouter::resolve_engine($path);
        if (!$compressor) {
            return ['success' => false, 'error' => 'No valid compressor available.'];
        }

        $engine = method_exists($compressor, 'get_engine_name')
            ? $compressor->get_engine_name()
            : Utils::short_class_name($compressor);

        $total_saved = 0;
        $original_size = 0;
        $optimized_size = 0;
        $thumbs_saved = 0;

        // Handle unscaled original file if applicable
        $unscaled_path = str_replace('-scaled', '', $path);
        if ($unscaled_path !== $path && file_exists($unscaled_path)) {
            $log = self::optimize_file($unscaled_path, $compressor);
            $total_saved += $log['saved'] ?? 0;
        }

        // Optimize main image
        $log = self::optimize_file($path, $compressor);
        $total_saved += $log['saved'] ?? 0;
        $original_size += $log['original'] ?? 0;
        $optimized_size += $log['optimized'] ?? 0;

        // Optimize thumbnails using Utils method to store detailed info
        $thumbs_saved = Utils::compress_thumbnails($attachment_id, $compressor, $path);

        // Update compression metadata
        Utils::update_compression_metadata(
            $attachment_id,
            $total_saved,
            method_exists($compressor, 'get_engine_name') ? $compressor->get_engine_name() : Utils::short_class_name($compressor),
            true,
            $original_size,
            $optimized_size,
            $thumbs_saved
        );

        return [
            'success' => true,
            'saved' => $total_saved,
            'original' => $original_size,
            'optimized' => $optimized_size,
            'thumbnails' => $thumbs_saved,
            'engine' => $engine
        ];
    }

    /**
     * Optimize a single image file using the given compressor.
     *
     * @param string $file_path
     * @param CompressorInterface $compressor
     * @return array
     */
    protected static function optimize_file(string $file_path, CompressorInterface $compressor): array {
        if (!file_exists($file_path)) {
            return ['success' => false, 'error' => 'File not found: ' . $file_path];
        }

        $original_size = filesize($file_path);
        $result = $compressor->compress($file_path);

        if (!$result['success']) {
            return $result;
        }

        $optimized_size = file_exists($file_path) ? filesize($file_path) : $original_size;
        $saved = max(0, $original_size - $optimized_size);

        return [
            'success' => true,
            'saved' => $saved,
            'original' => $original_size,
            'optimized' => $optimized_size,
        ];
    }

    /**
     * Summarize the space savings for an attachment.
     *
     * @param int $attachment_id
     * @return array
     */
    public static function summarize_optimization(int $attachment_id): array {
        $original = (int) get_post_meta($attachment_id, '_pic_pilot_original_size', true);
        $optimized = (int) get_post_meta($attachment_id, '_pic_pilot_optimized_size', true);
        $thumbs = (int) get_post_meta($attachment_id, '_pic_pilot_optimized_thumbs', true);
        $total_saved = (int) get_post_meta($attachment_id, '_pic_pilot_bytes_saved', true);
        $meta = wp_get_attachment_metadata($attachment_id);
        
        // Calculate main image savings
        $main_saved = max(0, $original - $optimized);
        
        // If we have original/optimized data, use it; otherwise fall back to total_saved
        if ($original > 0 && $optimized > 0) {
            $saved_percent = round(($main_saved / $original) * 100);
        } else if ($total_saved > 0) {
            // Fallback: use total_saved as percentage estimate
            $current_file = get_attached_file($attachment_id);
            $current_size = file_exists($current_file) ? filesize($current_file) : 0;
            if ($current_size > 0) {
                $estimated_original = $current_size + $total_saved;
                $saved_percent = round(($total_saved / $estimated_original) * 100);
            } else {
                $saved_percent = 0;
            }
        } else {
            $saved_percent = 0;
        }

        Logger::log('🧪 Summary check', [
            'attachment_id' => $attachment_id,
            'original' => $original,
            'optimized' => $optimized,
            'thumbs' => $thumbs,
            'total_saved' => $total_saved,
            'saved_percent' => $saved_percent,
            'engine' => get_post_meta($attachment_id, '_pic_pilot_engine', true),
        ]);

        return [
            'total' => $original,
            'main_saved' => $main_saved,
            'thumbs' => $thumbs,
            'saved_percent' => $saved_percent,
            'main_percent' => $saved_percent, // For main image, same as saved_percent
            'thumb_percent' => $thumbs > 0 && $original > 0 ? round(($thumbs / $original) * 100) : 0,
            'engine' => get_post_meta($attachment_id, '_pic_pilot_engine', true) ?? 'unknown',
            'thumb_count' => is_array($meta['sizes'] ?? null) ? count($meta['sizes']) : 0,
        ];
    }

    /**
     * Handle image resizing during optimization if enabled
     *
     * @param int $attachment_id
     * @param string $path
     * @return string The new path (may be the same or a resized version)
     */
    protected static function handle_optimization_resize(int $attachment_id, string $path): string {
        $max_width = Settings::get_optimization_max_width();
        if ($max_width <= 0) {
            return $path;
        }

        Logger::log("📦 Optimization resize settings: max_width = {$max_width}");

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            Logger::log("❌ Could not get image editor for optimization resize: " . $editor->get_error_message());
            return $path;
        }

        $size = $editor->get_size();
        Logger::log("🧪 Optimization resize check: width = {$size['width']}, max = {$max_width}");

        if ($size['width'] > $max_width) {
            $original_size = filesize($path);
            
            Logger::log("🔄 Resizing image during optimization from {$size['width']}px to {$max_width}px");
            
            // Check if we should keep the original
            $keep_original = Settings::is_enabled('keep_original_after_optimization_resize');
            Logger::log("🔧 keep_original_after_optimization_resize setting: " . ($keep_original ? 'true' : 'false'));
            
            if ($keep_original) {
                // Create backup before resize for restoration purposes
                if (Settings::is_enabled('enable_backup')) {
                    BackupService::create_backup($attachment_id);
                }
                
                // When keeping original, create a copy with a different name for the resized version
                $path_info = pathinfo($path);
                $resized_path = $path_info['dirname'] . '/' . $path_info['filename'] . '-resized.' . $path_info['extension'];
                
                $resize_result = $editor->resize($max_width, null, false);
                if (is_wp_error($resize_result)) {
                    Logger::log("❌ Optimization resize failed: " . $resize_result->get_error_message());
                    return $path;
                }

                $save_result = $editor->save($resized_path);
                if (is_wp_error($save_result)) {
                    Logger::log("❌ Save optimization resized version failed: " . $save_result->get_error_message());
                    return $path;
                }
                
                // Update the attachment to point to the resized version but keep original file
                update_attached_file($attachment_id, $resized_path);
                
                $new_size = filesize($resized_path);
                $savings = $original_size - $new_size;
                Logger::log("✅ Optimization resize completed: {$original_size} → {$new_size} bytes (Saved: {$savings})");
                Logger::log("📁 Kept original pre-optimization resize: " . basename($path));
                Logger::log("📁 Created resized version: " . basename($resized_path));
                
                // Store resize information for reporting
                $final_editor = wp_get_image_editor($resized_path);
                if (!is_wp_error($final_editor)) {
                    $final_dimensions = $final_editor->get_size();
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    if (!$metadata) $metadata = [];
                    $metadata['pic_pilot_resize_info'] = [
                        'original_dimensions' => $size,
                        'final_dimensions' => $final_dimensions,
                        'resized' => true,
                        'mode' => 'optimization',
                        'max_width' => $max_width,
                        'original_kept' => true
                    ];
                    wp_update_attachment_metadata($attachment_id, $metadata);
                    Logger::log("💾 Stored optimization resize info: {$size['width']}×{$size['height']} → {$final_dimensions['width']}×{$final_dimensions['height']}, original kept: YES");
                }
                
                // Regenerate thumbnails based on the new resized dimensions
                self::regenerate_thumbnails_after_resize($attachment_id, $resized_path, $max_width);
                
                return $resized_path;
            } else {
                // Resize in-place when not keeping original
                $resize_result = $editor->resize($max_width, null, false);
                if (is_wp_error($resize_result)) {
                    Logger::log("❌ Optimization resize failed: " . $resize_result->get_error_message());
                    return $path;
                }

                $save_result = $editor->save($path);
                if (is_wp_error($save_result)) {
                    Logger::log("❌ Save after optimization resize failed: " . $save_result->get_error_message());
                    return $path;
                }

                $new_size = filesize($path);
                $savings = $original_size - $new_size;
                Logger::log("✅ Optimization resize completed: {$original_size} → {$new_size} bytes (Saved: {$savings})");
                Logger::log("🧹 Resized in-place during optimization, original file replaced: " . basename($path));
                
                // Store resize information for reporting
                $final_editor = wp_get_image_editor($path);
                if (!is_wp_error($final_editor)) {
                    $final_dimensions = $final_editor->get_size();
                    $metadata = wp_get_attachment_metadata($attachment_id);
                    if (!$metadata) $metadata = [];
                    $metadata['pic_pilot_resize_info'] = [
                        'original_dimensions' => $size,
                        'final_dimensions' => $final_dimensions,
                        'resized' => true,
                        'mode' => 'optimization',
                        'max_width' => $max_width,
                        'original_kept' => false
                    ];
                    wp_update_attachment_metadata($attachment_id, $metadata);
                    Logger::log("💾 Stored optimization resize info: {$size['width']}×{$size['height']} → {$final_dimensions['width']}×{$final_dimensions['height']}, original kept: NO");
                }
                
                // Regenerate thumbnails based on the new resized dimensions
                self::regenerate_thumbnails_after_resize($attachment_id, $path, $max_width);
                
                return $path;
            }
        }

        return $path;
    }

    /**
     * Regenerate thumbnails after resizing to ensure no thumbnail is larger than the main image
     *
     * @param int $attachment_id
     * @param string $resized_path
     * @param int $max_width
     */
    protected static function regenerate_thumbnails_after_resize(int $attachment_id, string $resized_path, int $max_width): void {
        Logger::log("🔄 Regenerating thumbnails after resize to ensure consistency");
        
        // Get current attachment metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata) {
            Logger::log("❌ Could not get attachment metadata for thumbnail regeneration");
            return;
        }
        
        // Get WordPress registered image sizes
        $image_sizes = wp_get_registered_image_subsizes();
        $upload_dir = wp_upload_dir();
        $file_dir = dirname($resized_path);
        
        // Clear ALL existing thumbnails when we've resized the main image
        // This prevents duplication when we keep the original
        $removed_thumbnails_data = [];
        $total_savings = 0;
        
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $thumb_path = $file_dir . '/' . $size_data['file'];
                if (file_exists($thumb_path)) {
                    // Check if this thumbnail would be considered "oversized" for removal tracking
                    $thumb_size = getimagesize($thumb_path);
                    $file_size = filesize($thumb_path);
                    
                    if ($thumb_size && $thumb_size[0] > $max_width) {
                        // Track this as a removed oversized thumbnail
                        $removed_thumbnails_data[] = [
                            'size_name' => $size_name,
                            'file' => $size_data['file'],
                            'width' => $thumb_size[0],
                            'height' => $thumb_size[1],
                            'file_size' => $file_size
                        ];
                        $total_savings += $file_size;
                        Logger::log("🧹 Removed oversized thumbnail [{$size_name}]: {$size_data['file']} ({$thumb_size[0]}px, " . size_format($file_size) . ") - optimization mode");
                    } else {
                        Logger::log("🗑️ Deleted existing thumbnail: {$size_data['file']} (regenerating based on resized image)");
                    }
                    
                    unlink($thumb_path);
                }
            }
            // Clear all thumbnail metadata
            $metadata['sizes'] = [];
        }
        
        // Store removed thumbnails data for reporting if any were removed
        if (!empty($removed_thumbnails_data)) {
            $metadata['pic_pilot_removed_thumbnails'] = [
                'thumbnails' => $removed_thumbnails_data,
                'total_savings' => $total_savings,
                'max_width' => $max_width,
                'mode' => 'optimization'
            ];
            Logger::log("💾 Captured " . count($removed_thumbnails_data) . " removed thumbnails data during optimization, total savings: " . size_format($total_savings));
        }
        
        // Regenerate thumbnails with the new constraints
        $editor = wp_get_image_editor($resized_path);
        if (is_wp_error($editor)) {
            Logger::log("❌ Could not get image editor for thumbnail regeneration");
            return;
        }
        
        $original_size = $editor->get_size();
        $new_sizes = [];
        
        foreach ($image_sizes as $size_name => $size_data) {
            $width = min($size_data['width'], $max_width);
            $height = $size_data['height'];
            $crop = $size_data['crop'];
            
            // Skip if the thumbnail would be larger than our main image
            if ($width >= $max_width) {
                Logger::log("⏭️ Skipping thumbnail '{$size_name}' ({$width}px >= {$max_width}px)");
                continue;
            }
            
            // Skip if original image is smaller than thumbnail size
            if ($original_size['width'] <= $width && $original_size['height'] <= $height) {
                Logger::log("⏭️ Skipping thumbnail '{$size_name}' (original is smaller)");
                continue;
            }
            
            $resized = $editor->multi_resize([
                $size_name => [
                    'width' => $width,
                    'height' => $height,
                    'crop' => $crop
                ]
            ]);
            
            if (!is_wp_error($resized) && isset($resized[$size_name])) {
                $new_sizes[$size_name] = $resized[$size_name];
                Logger::log("✅ Generated thumbnail '{$size_name}': {$resized[$size_name]['file']} ({$resized[$size_name]['width']}x{$resized[$size_name]['height']})");
            }
        }
        
        // Update metadata with new thumbnail information
        if (!empty($new_sizes)) {
            $metadata['sizes'] = array_merge($metadata['sizes'] ?? [], $new_sizes);
        }
        
        // Always update metadata to ensure removed thumbnails data is saved
        wp_update_attachment_metadata($attachment_id, $metadata);
        Logger::log("📝 Updated attachment metadata" . (!empty($new_sizes) ? " with " . count($new_sizes) . " new thumbnails" : " (removed thumbnails tracked)"));
    }
}
