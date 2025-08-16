<?php

namespace PicPilot\Upload;

use PicPilot\Settings;
use PicPilot\Logger;
use PicPilot\WebPConverter;
use PicPilot\PngToJpegConverter;
use PicPilot\Optimizer;
use PicPilot\Backup\BackupService;
use PicPilot\Backup\SmartBackupManager;
use PicPilot\Utils;

defined('ABSPATH') || exit;

class UploadProcessor {
    
    public static function process_upload(array $metadata, int $attachment_id): array {
        global $pic_pilot_restoring, $pic_pilot_inline_converting;
        if (!empty($pic_pilot_restoring)) {
            Logger::log("🛑 Skipping upload processing during restore for ID $attachment_id");
            return $metadata;
        }
        
        if (!empty($pic_pilot_inline_converting)) {
            Logger::log("🛑 Skipping upload processing during inline conversion for ID $attachment_id");
            return $metadata;
        }

        $upload_mode = Settings::get('upload_mode', 'disabled');
        Logger::log("🎯 Upload mode: $upload_mode for ID $attachment_id");

        switch ($upload_mode) {
            case 'convert':
                return self::handle_conversion_mode($metadata, $attachment_id);
            case 'compress':
                return self::handle_compression_mode($metadata, $attachment_id);
            case 'disabled':
            default:
                Logger::log("📴 Upload processing disabled");
                return $metadata;
        }
    }

    /**
     * Handle CONVERT mode: Convert images to WebP format
     */
    private static function handle_conversion_mode(array $metadata, int $attachment_id): array {
        Logger::log("🔄 Processing in WebP CONVERT mode for ID $attachment_id");
        
        // In conversion mode, WebP conversion is automatic
        Logger::log("🌐 WebP conversion is automatic in conversion mode");
        
        $file = get_attached_file($attachment_id);
        if (!file_exists($file)) {
            Logger::log("❌ File not found: $file");
            return $metadata;
        }

        $mime = mime_content_type($file);
        Logger::log("📋 File MIME type: $mime");

        // Handle resize if enabled (before conversion)
        if (Settings::is_enabled('resize_during_conversion')) {
            $metadata = self::handle_resize_during_conversion($metadata, $attachment_id, $file);
            // Refresh file path after potential resize
            $file = get_attached_file($attachment_id);
        }

        // Convert to WebP if supported format
        if (self::should_convert_to_webp($mime)) {
            return self::convert_to_webp($metadata, $attachment_id, $file, $mime);
        }

        Logger::log("📄 No WebP conversion available for $mime format");
        return $metadata;
    }

    /**
     * Handle COMPRESS mode: Optimize same format (JPEG → JPEG, PNG → PNG)  
     */
    private static function handle_compression_mode(array $metadata, int $attachment_id): array {
        Logger::log("🗜️ Processing in COMPRESS mode for ID $attachment_id");

        // Handle PNG→JPEG conversion FIRST (before any resizing) to ensure proper backup
        $file = get_attached_file($attachment_id);
        $mime = get_post_mime_type($attachment_id);
        
        if ($mime === 'image/png' && Settings::is_enabled('convert_png_to_jpeg_in_compress_mode')) {
            Logger::log("🔍 PNG→JPEG setting enabled, checking if PNG is opaque");
            if (Utils::isOpaquePng($file)) {
                Logger::log("✅ PNG is opaque, proceeding with PNG→JPEG conversion (BEFORE resize)");
                return self::convert_png_to_jpeg_in_compress_mode($metadata, $attachment_id, $file);
            } else {
                Logger::log("❌ PNG has transparency, skipping PNG→JPEG conversion");
            }
        } else {
            Logger::log("🔍 PNG→JPEG conversion: mime=$mime, setting_enabled=" . (Settings::is_enabled('convert_png_to_jpeg_in_compress_mode') ? 'true' : 'false'));
        }

        // Handle resize if enabled (after conversion, if any)
        $file = get_attached_file($attachment_id);
        if (Settings::is_enabled('resize_during_compression') && $file && file_exists($file)) {
            $metadata = self::handle_resize_during_compression($metadata, $attachment_id, $file);
        }
        
        // Skip compression for WebP (already optimal)
        if ($mime === 'image/webp') {
            Logger::log('🌐 Skipping compression for WebP file (already optimized format)');
            return $metadata;
        }

        // Only compress valid image files
        if (strpos($mime, 'image/') !== 0) {
            Logger::log('🚫 Not an image upload: ' . $mime);
            return $metadata;
        }

        // Compress using selected engine (skip resize since we handled it above)
        $result = Optimizer::optimize_attachment($attachment_id, false);

        if (!$result['success']) {
            Logger::log('❌ Upload compression failed: ' . ($result['error'] ?? 'unknown'));
        } else {
            Logger::log('✅ Upload compression succeeded for ID: ' . $attachment_id);
        }

        return $metadata;
    }

    /**
     * Convert to WebP
     */
    private static function convert_to_webp(array $metadata, int $attachment_id, string $file, string $mime): array {
        Logger::log("🌐 Starting WebP conversion for ID $attachment_id");

        $original_size = filesize($file);
        
        // Smart backup for WebP conversion
        SmartBackupManager::create_smart_backup($attachment_id, 'convert_to_webp');

        $quality = (int) Settings::get('webp_quality', 80);
        $engine = Settings::get('webp_engine', 'local');

        // Convert using selected engine
        if ($engine === 'tinypng') {
            // TODO: Implement TinyPNG WebP conversion
            Logger::log("⚠️ TinyPNG WebP conversion not yet implemented, falling back to local");
            $webp_path = WebPConverter::convert($file, $quality);
        } else {
            $webp_path = WebPConverter::convert($file, $quality);
        }

        if (!$webp_path || !file_exists($webp_path)) {
            Logger::log("❌ WebP conversion failed");
            return $metadata;
        }

        $metadata = self::finalize_format_conversion($metadata, $attachment_id, $file, $webp_path, 'image/webp', $original_size);
        
        // Store the original conversion savings before compression
        $conversion_savings = $original_size - filesize($webp_path);
        
        // Also compress the WebP file for maximum optimization
        Logger::log("🗜️ Compressing WebP file after conversion for maximum optimization");
        $compress_result = Optimizer::optimize_attachment($attachment_id, false);
        
        if ($compress_result['success']) {
            Logger::log("✅ Post-conversion compression succeeded");
            
            // Combine conversion + compression savings for total savings display
            $post_compression_size = filesize(get_attached_file($attachment_id));
            $total_savings = $original_size - $post_compression_size;
            $compression_additional_savings = isset($compress_result['saved']) ? $compress_result['saved'] : 0;
            
            Logger::log("📊 Combined conversion+compression savings: {$original_size} → {$post_compression_size} bytes (Total saved: {$total_savings}, Conversion: {$conversion_savings}, Additional compression: {$compression_additional_savings})");
            
            // Update metadata to reflect total savings from original
            update_post_meta($attachment_id, '_pic_pilot_conversion_savings', $total_savings);
            update_post_meta($attachment_id, '_pic_pilot_original_size', $original_size);
            update_post_meta($attachment_id, '_pic_pilot_optimized_size', $post_compression_size);
            update_post_meta($attachment_id, '_pic_pilot_bytes_saved', $total_savings);
            update_post_meta($attachment_id, '_pic_pilot_engine', 'Format Conversion + Compression');
            
            // Update optimization status with total savings
            update_post_meta($attachment_id, '_pic_pilot_optimization', [
                'status' => 'optimized',
                'saved' => $total_savings,
                'timestamp' => time(),
            ]);
            
        } else {
            Logger::log("⚠️ Post-conversion compression failed: " . ($compress_result['error'] ?? 'unknown'));
        }
        
        return $metadata;
    }


    /**
     * Finalize format conversion (WebP)
     */
    private static function finalize_format_conversion(array $metadata, int $attachment_id, string $old_file, string $new_file, string $new_mime, int $original_size): array {
        $new_size = filesize($new_file);
        $savings = max(0, $original_size - $new_size);
        
        Logger::log("📊 Format conversion: {$original_size} → {$new_size} bytes (Saved: {$savings})");

        // Handle resize on converted file if needed
        if (Settings::is_enabled('resize_during_conversion') && !Settings::is_enabled('keep_original_after_conversion_resize')) {
            $new_file = self::handle_resize_after_conversion($new_file);
            $new_size = filesize($new_file);
            $total_savings = $original_size - $new_size;
            Logger::log("📊 Total conversion+resize savings: {$original_size} → {$new_size} bytes (Saved: {$total_savings})");
            $savings = $total_savings;
        }

        $old_url = wp_get_attachment_url($attachment_id);
        $new_url = str_replace(basename($old_file), basename($new_file), $old_url);

        // Update WordPress attachment
        update_attached_file($attachment_id, $new_file);
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $new_mime,
        ]);

        // Delete old thumbnails
        foreach ($metadata['sizes'] ?? [] as $size) {
            $thumb_path = path_join(dirname($old_file), $size['file']);
            if (file_exists($thumb_path)) {
                @unlink($thumb_path);
            }
        }

        // Delete original file
        Logger::log("🧹 Deleting original file after conversion: " . basename($old_file));
        @unlink($old_file);

        // Store conversion metadata using the standard optimization format (get MIME before file deletion)
        $original_mime = file_exists($old_file) ? mime_content_type($old_file) : 'unknown';
        update_post_meta($attachment_id, '_pic_pilot_conversion_savings', $savings);
        update_post_meta($attachment_id, '_pic_pilot_original_format', $original_mime);
        update_post_meta($attachment_id, '_pic_pilot_original_size', $original_size);
        update_post_meta($attachment_id, '_pic_pilot_optimized_size', $new_size);
        update_post_meta($attachment_id, '_pic_pilot_bytes_saved', $savings);
        update_post_meta($attachment_id, '_pic_pilot_engine', 'Format Conversion');
        update_post_meta($attachment_id, '_pic_pilot_optimized_version', time());
        
        // Set optimization status
        update_post_meta($attachment_id, '_pic_pilot_optimization', [
            'status' => 'optimized',
            'saved' => $savings,
            'timestamp' => time(),
        ]);

        // Regenerate thumbnails
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $new_file);

        // Clean up oversized thumbnails
        if (Settings::is_enabled('resize_during_conversion')) {
            $new_metadata = self::clean_oversized_thumbnails($new_metadata, $new_file, 'conversion');
        }

        wp_update_attachment_metadata($attachment_id, $new_metadata);

        // Replace content references
        if (class_exists('\PicPilot\Restore\ContentUpdater')) {
            \PicPilot\Restore\ContentUpdater::replace_image_urls($attachment_id, $old_url, $new_url);
        }

        clean_post_cache($attachment_id);
        Logger::log("✅ Format conversion completed for ID $attachment_id");
        
        return $new_metadata;
    }

    /**
     * Should convert to WebP?
     */
    private static function should_convert_to_webp(string $mime): bool {
        // In conversion mode, WebP conversion is automatic for supported formats
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
            return false;
        }

        return WebPConverter::hasWebPSupport();
    }


    /**
     * Handle resize during compression mode
     */
    private static function handle_resize_during_compression(array $metadata, int $attachment_id, string $path): array {
        $max_width = (int) Settings::get('compression_max_width', 2048);
        if ($max_width <= 0) {
            $max_width = 2048;
        }

        Logger::log("📦 Compression resize settings: max_width = {$max_width}");

        return self::perform_resize($metadata, $attachment_id, $path, $max_width, 'keep_original_after_compression_resize', 'compression');
    }

    /**
     * Handle resize during conversion mode
     */
    private static function handle_resize_during_conversion(array $metadata, int $attachment_id, string $path): array {
        $max_width = (int) Settings::get('conversion_max_width', 2048);
        if ($max_width <= 0) {
            $max_width = 2048;
        }

        Logger::log("📦 Conversion resize settings: max_width = {$max_width}");

        return self::perform_resize($metadata, $attachment_id, $path, $max_width, 'keep_original_after_conversion_resize', 'conversion');
    }

    /**
     * Perform resize operation (shared logic)
     */
    private static function perform_resize(array $metadata, int $attachment_id, string $path, int $max_width, string $keep_original_setting, string $mode): array {
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            Logger::log("❌ Could not get image editor for resize: " . $editor->get_error_message());
            return $metadata;
        }

        $size = $editor->get_size();
        Logger::log("🧪 Resize check: width = {$size['width']}, max = {$max_width}");

        if ($size['width'] > $max_width) {
            $original_size = filesize($path);
            $original_dimensions = $size; // Store original dimensions for reporting
            Logger::log("🔄 Resizing image from {$size['width']}px to {$max_width}px (mode: {$mode})");

            $keep_original = Settings::is_enabled($keep_original_setting);
            
            // For compression mode with PNG→JPEG conversion, always resize in-place
            // The backup system handles preservation, and we don't want intermediate files
            if ($mode === 'compression' && Settings::is_enabled('convert_png_to_jpeg_in_compress_mode')) {
                // Resize in-place for PNG→JPEG conversion
                $resize_result = $editor->resize($max_width, null, false);
                if (!is_wp_error($resize_result)) {
                    $save_result = $editor->save($path);
                    if (!is_wp_error($save_result)) {
                        $new_size = filesize($path);
                        Logger::log("✅ Resize in-place for PNG→JPEG conversion: {$original_size} → {$new_size} bytes");
                    }
                }
            } else if ($keep_original) {
                // Create backup and resize to new file (normal cases)
                SmartBackupManager::create_smart_backup($attachment_id, 'resize_with_original');
                
                $path_info = pathinfo($path);
                $resized_path = $path_info['dirname'] . '/' . $path_info['filename'] . '-resized.' . $path_info['extension'];
                
                $resize_result = $editor->resize($max_width, null, false);
                if (!is_wp_error($resize_result)) {
                    $save_result = $editor->save($resized_path);
                    if (!is_wp_error($save_result)) {
                        update_attached_file($attachment_id, $resized_path);
                        $new_size = filesize($resized_path);
                        Logger::log("✅ Resize with original kept: {$original_size} → {$new_size} bytes");
                    }
                }
            } else {
                // Resize in-place (delete original)
                $resize_result = $editor->resize($max_width, null, false);
                if (!is_wp_error($resize_result)) {
                    $save_result = $editor->save($path);
                    if (!is_wp_error($save_result)) {
                        $new_size = filesize($path);
                        Logger::log("✅ Resize in-place (original deleted): {$original_size} → {$new_size} bytes");
                    }
                }
            }

            // Clean up oversized thumbnails
            $metadata = self::clean_oversized_thumbnails($metadata, get_attached_file($attachment_id), $mode);
            
            // Store resize information for reporting
            $final_editor = wp_get_image_editor(get_attached_file($attachment_id));
            if (!is_wp_error($final_editor)) {
                $final_dimensions = $final_editor->get_size();
                $metadata['pic_pilot_resize_info'] = [
                    'original_dimensions' => $original_dimensions,
                    'final_dimensions' => $final_dimensions,
                    'resized' => true,
                    'mode' => $mode,
                    'max_width' => $max_width,
                    'original_kept' => $keep_original
                ];
                Logger::log("💾 Stored resize info: {$original_dimensions['width']}×{$original_dimensions['height']} → {$final_dimensions['width']}×{$final_dimensions['height']}, original kept: " . ($keep_original ? 'YES' : 'NO'));
            }
        }

        return $metadata;
    }

    /**
     * Handle resize after format conversion
     */
    private static function handle_resize_after_conversion(string $file_path): string {
        $max_width = (int) Settings::get('conversion_max_width', 2048);
        if ($max_width <= 0) {
            return $file_path;
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return $file_path;
        }

        $size = $editor->get_size();
        if ($size['width'] > $max_width) {
            $resize_result = $editor->resize($max_width, null, false);
            if (!is_wp_error($resize_result)) {
                $save_result = $editor->save($file_path);
                if (!is_wp_error($save_result)) {
                    Logger::log("🔄 Post-conversion resize: {$size['width']}px → {$max_width}px");
                }
            }
        }

        return $file_path;
    }

    /**
     * Clean up oversized thumbnails
     */
    private static function clean_oversized_thumbnails(array $metadata, string $main_file, string $mode = 'conversion'): array {
        // Use the appropriate max width based on mode
        $max_width_setting = $mode === 'compression' ? 'compression_max_width' : 'conversion_max_width';
        $max_width = (int) Settings::get($max_width_setting, 2048);
        
        if ($max_width <= 0 || !isset($metadata['sizes'])) {
            return $metadata;
        }

        $removed_thumbnails_data = [];
        $total_savings = 0;

        foreach ($metadata['sizes'] as $size_name => $info) {
            $thumb_path = path_join(dirname($main_file), $info['file']);
            if (file_exists($thumb_path)) {
                [$width] = getimagesize($thumb_path);
                if ($width > $max_width) {
                    $file_size = filesize($thumb_path);
                    $removed_thumbnails_data[] = [
                        'size_name' => $size_name,
                        'file' => $info['file'],
                        'width' => $width,
                        'height' => $info['height'] ?? 0,
                        'file_size' => $file_size
                    ];
                    $total_savings += $file_size;
                    
                    @unlink($thumb_path);
                    Logger::log("🧹 Removed oversized thumbnail [{$size_name}]: {$info['file']} ({$width}px, " . size_format($file_size) . ") - mode: {$mode}");
                    unset($metadata['sizes'][$size_name]);
                }
            }
        }

        // Store removed thumbnails data for reporting
        if (!empty($removed_thumbnails_data)) {
            $metadata['pic_pilot_removed_thumbnails'] = [
                'thumbnails' => $removed_thumbnails_data,
                'total_savings' => $total_savings,
                'max_width' => $max_width,
                'mode' => $mode
            ];
            Logger::log("💾 Captured " . count($removed_thumbnails_data) . " removed thumbnails data, total savings: " . size_format($total_savings));
        }

        return $metadata;
    }

    /**
     * Convert PNG to JPEG in compression mode (then compress the result)
     */
    private static function convert_png_to_jpeg_in_compress_mode(array $metadata, int $attachment_id, string $file): array {
        Logger::log("🔄 Starting PNG→JPEG conversion in compression mode for ID $attachment_id");

        $original_size = filesize($file);
        
        // Create conversion backup for PNG→JPEG conversion (format change)
        Logger::log("🔄 Auto-backup required for format conversion: convert_png_to_jpeg");
        if (class_exists('\\PicPilot\\Backup\\SmartBackupManager')) {
            try {
                $backup_success = \PicPilot\Backup\SmartBackupManager::create_smart_backup($attachment_id, 'convert_png_to_jpeg');
                if ($backup_success) {
                    Logger::log("✅ Created conversion backup for PNG→JPEG conversion for ID $attachment_id");
                } else {
                    Logger::log("⚠️ Conversion backup creation failed for ID $attachment_id");
                    Logger::log("🔄 Continuing with PNG→JPEG conversion despite backup failure");
                }
            } catch (\Exception $e) {
                Logger::log("❌ Exception during backup creation for ID $attachment_id: " . $e->getMessage());
                Logger::log("🔄 Continuing with PNG→JPEG conversion despite backup exception");
            }
        }

        $quality = (int) Settings::get('jpeg_quality', 80);
        Logger::log("🔄 Attempting PNG→JPEG conversion with quality: $quality");
        Logger::log("🔄 Source file: " . basename($file));
        
        $jpeg_path = PngToJpegConverter::convert($file, $quality);
        
        Logger::log("🔄 PNG→JPEG conversion result: " . ($jpeg_path ? "SUCCESS - " . basename($jpeg_path) : "FAILED"));

        if (!$jpeg_path || !file_exists($jpeg_path)) {
            Logger::log("❌ PNG→JPEG conversion failed in compression mode - no JPEG file created");
            // Fall back to regular PNG compression
            Logger::log("🔄 Falling back to PNG compression");
            $result = Optimizer::optimize_attachment($attachment_id, false);
            return $metadata;
        }
        
        Logger::log("✅ PNG→JPEG conversion successful: " . basename($file) . " → " . basename($jpeg_path));

        // Finalize the conversion for compression mode (respects compression settings)
        $metadata = self::finalize_compression_mode_conversion($metadata, $attachment_id, $file, $jpeg_path, 'image/jpeg', $original_size);
        
        // Store the original conversion savings before compression
        $conversion_savings = $original_size - filesize($jpeg_path);
        
        // Also compress the JPEG file for maximum optimization
        Logger::log("🗜️ Compressing converted JPEG file in compression mode");
        $compress_result = Optimizer::optimize_attachment($attachment_id, false);
        
        if ($compress_result['success']) {
            Logger::log("✅ Post-conversion compression succeeded in compression mode");
            
            // Combine conversion + compression savings for total savings display
            $post_compression_size = filesize(get_attached_file($attachment_id));
            $total_savings = $original_size - $post_compression_size;
            $compression_additional_savings = isset($compress_result['saved']) ? $compress_result['saved'] : 0;
            
            Logger::log("📊 Combined conversion+compression savings: {$original_size} → {$post_compression_size} bytes (Total saved: {$total_savings}, Conversion: {$conversion_savings}, Additional compression: {$compression_additional_savings})");
            
            // Update metadata to reflect total savings from original PNG
            update_post_meta($attachment_id, '_pic_pilot_conversion_savings', $total_savings);
            update_post_meta($attachment_id, '_pic_pilot_original_size', $original_size);
            update_post_meta($attachment_id, '_pic_pilot_optimized_size', $post_compression_size);
            update_post_meta($attachment_id, '_pic_pilot_bytes_saved', $total_savings);
            update_post_meta($attachment_id, '_pic_pilot_engine', 'PNG→JPEG + Compression');
            
            // Update optimization status with total savings
            update_post_meta($attachment_id, '_pic_pilot_optimization', [
                'status' => 'optimized',
                'saved' => $total_savings,
                'timestamp' => time(),
            ]);
            
        } else {
            Logger::log("⚠️ Post-conversion compression failed in compression mode: " . ($compress_result['error'] ?? 'unknown'));
        }
        
        return $metadata;
    }

    /**
     * Finalize PNG→JPEG conversion in compression mode (respects compression mode settings)
     */
    private static function finalize_compression_mode_conversion(array $metadata, int $attachment_id, string $old_file, string $new_file, string $new_mime, int $original_size): array {
        $new_size = filesize($new_file);
        $savings = max(0, $original_size - $new_size);
        
        Logger::log("📊 Format conversion: {$original_size} → {$new_size} bytes (Saved: {$savings})");

        // Handle resize on converted file if needed (for compression mode)
        if (Settings::is_enabled('resize_during_compression') && !Settings::is_enabled('keep_original_after_compression_resize')) {
            $new_file = self::handle_resize_after_compression_conversion($new_file);
            $new_size = filesize($new_file);
            $total_savings = $original_size - $new_size;
            Logger::log("📊 Total conversion+resize savings: {$original_size} → {$new_size} bytes (Saved: {$total_savings})");
            $savings = $total_savings;
        }

        $old_url = wp_get_attachment_url($attachment_id);
        $new_url = str_replace(basename($old_file), basename($new_file), $old_url);

        // Update WordPress attachment
        update_attached_file($attachment_id, $new_file);
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $new_mime,
        ]);

        // Delete old thumbnails
        foreach ($metadata['sizes'] ?? [] as $size) {
            $thumb_path = path_join(dirname($old_file), $size['file']);
            if (file_exists($thumb_path)) {
                @unlink($thumb_path);
            }
        }

        // Store conversion metadata BEFORE deleting the file
        $original_mime = file_exists($old_file) ? mime_content_type($old_file) : 'image/png';
        update_post_meta($attachment_id, '_pic_pilot_conversion_savings', $savings);
        update_post_meta($attachment_id, '_pic_pilot_original_format', $original_mime);
        
        // Always delete the intermediate PNG file - backup system handles preservation
        Logger::log("🧹 Deleting intermediate PNG file after conversion: " . basename($old_file));
        @unlink($old_file);
        update_post_meta($attachment_id, '_pic_pilot_original_size', $original_size);
        update_post_meta($attachment_id, '_pic_pilot_optimized_size', $new_size);
        update_post_meta($attachment_id, '_pic_pilot_bytes_saved', $savings);
        update_post_meta($attachment_id, '_pic_pilot_engine', 'PNG→JPEG Conversion');
        update_post_meta($attachment_id, '_pic_pilot_optimized_version', time());
        
        // Set optimization status
        update_post_meta($attachment_id, '_pic_pilot_optimization', [
            'status' => 'optimized',
            'saved' => $savings,
            'timestamp' => time(),
        ]);

        // Regenerate thumbnails
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $new_file);

        // Clean up oversized thumbnails based on compression settings
        if (Settings::is_enabled('resize_during_compression')) {
            $new_metadata = self::clean_oversized_thumbnails($new_metadata, $new_file, 'compression');
        }

        wp_update_attachment_metadata($attachment_id, $new_metadata);

        // Replace content references
        if (class_exists('\PicPilot\Restore\ContentUpdater')) {
            \PicPilot\Restore\ContentUpdater::replace_image_urls($attachment_id, $old_url, $new_url);
        }

        clean_post_cache($attachment_id);
        Logger::log("✅ Format conversion completed for ID $attachment_id");
        
        return $new_metadata;
    }

    /**
     * Handle resize after compression mode conversion
     */
    private static function handle_resize_after_compression_conversion(string $file_path): string {
        $max_width = (int) Settings::get('compression_max_width', 2048);
        if ($max_width <= 0) {
            return $file_path;
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return $file_path;
        }

        $size = $editor->get_size();
        if ($size['width'] > $max_width) {
            $resize_result = $editor->resize($max_width, null, false);
            if (!is_wp_error($resize_result)) {
                $save_result = $editor->save($file_path);
                if (!is_wp_error($save_result)) {
                    Logger::log("🔄 Post-conversion resize (compression mode): {$size['width']}px → {$max_width}px");
                }
            }
        }

        return $file_path;
    }
}