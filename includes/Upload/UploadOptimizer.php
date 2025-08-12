<?php

namespace PicPilot\Upload;

use PicPilot\Settings;
use PicPilot\Logger;
use PicPilot\Compressor\EngineRouter;
use PicPilot\Backup\BackupService;
use PicPilot\Utils;
use PicPilot\Optimizer;
use PicPilot\PngToJpegConverter;
use PicPilot\WebPConverter;
use PicPilot\Upload\UploadProcessor;


defined('ABSPATH') || exit;

class UploadOptimizer {
    protected $router;
    protected $backup;
    protected $logger;

    public function __construct(EngineRouter $router, Logger $logger) {
        $this->router = $router;
        $this->logger = $logger;
        // New clean architecture - single entry point
        add_filter('wp_generate_attachment_metadata', [UploadProcessor::class, 'process_upload'], 10, 2);
    }

    // Convert images to WebP if the setting is enabled (highest priority)
    public static function maybeConvertToWebP(array $metadata, int $attachment_id): array {
        global $pic_pilot_restoring;
        if (!empty($pic_pilot_restoring)) {
            Logger::log("🛑 Skipping WebP conversion during restore for ID $attachment_id");
            return $metadata;
        }

        // Check if WebP conversion is enabled
        if (!Settings::is_enabled('convert_to_webp_on_upload')) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!file_exists($file)) {
            return $metadata;
        }

        $mime = mime_content_type($file);
        
        // Only convert supported image formats
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
            return $metadata;
        }

        // Check if WebP conversion is possible
        if (!WebPConverter::canConvert($file)) {
            Logger::log("⚠️ WebP conversion not available for this server/image type");
            return $metadata;
        }

        Logger::log("🔄 Starting WebP conversion for ID $attachment_id (MIME: $mime)");

        // Record original size for savings calculation
        $original_size = filesize($file);
        Logger::log("📊 Original file size: {$original_size} bytes");

        // Backup original file and metadata
        if (Settings::is_enabled('enable_backup')) {
            BackupService::create_backup($attachment_id);
        }

        // Convert to WebP
        $quality = (int) Settings::get('webp_quality', 80);
        $webp_path = WebPConverter::convert($file, $quality);
        
        if (!$webp_path || !file_exists($webp_path)) {
            Logger::log("❌ WebP conversion failed for ID $attachment_id");
            return $metadata;
        }

        $webp_size = filesize($webp_path);
        $conversion_savings = max(0, $original_size - $webp_size);
        Logger::log("🔄 WebP conversion: {$original_size} → {$webp_size} bytes (Saved: {$conversion_savings})");

        // Handle resize on the newly converted WebP if needed
        $should_resize = Settings::is_enabled('resize_on_upload') && !Settings::is_enabled('keep_original_after_resize');
        
        if ($should_resize) {
            $max_width = (int) Settings::get('resize_max_width', 2048);
            if ($max_width <= 0) {
                $max_width = 2048;
            }
            
            Logger::log("📦 WebP resize check: max_width = {$max_width}");
            
            $editor = wp_get_image_editor($webp_path);
            if (!is_wp_error($editor)) {
                $size = $editor->get_size();
                Logger::log("🧪 WebP resize check: width = {$size['width']}, max = {$max_width}");
                
                if ($size['width'] > $max_width) {
                    $pre_resize_size = filesize($webp_path);
                    Logger::log("🔄 Resizing converted WebP from {$size['width']}px to {$max_width}px");
                    
                    $resize_result = $editor->resize($max_width, null, false);
                    if (!is_wp_error($resize_result)) {
                        $save_result = $editor->save($webp_path);
                        if (!is_wp_error($save_result)) {
                            $post_resize_size = filesize($webp_path);
                            $resize_savings = $pre_resize_size - $post_resize_size;
                            Logger::log("✅ WebP resize completed: {$pre_resize_size} → {$post_resize_size} bytes (Saved: {$resize_savings})");
                            
                            // Update conversion savings to include resize
                            $total_conversion_savings = $original_size - $post_resize_size;
                            Logger::log("📊 Total WebP+resize savings: {$original_size} → {$post_resize_size} bytes (Saved: {$total_conversion_savings})");
                            $conversion_savings = $total_conversion_savings;
                            $webp_size = $post_resize_size;
                        }
                    }
                }
            }
        }

        $old_url = wp_get_attachment_url($attachment_id);
        $new_url = str_replace(basename($file), basename($webp_path), $old_url);

        // Update the attached file reference
        update_attached_file($attachment_id, $webp_path);

        // Update post_mime_type in the attachment post
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => 'image/webp',
        ]);

        // Delete old thumbnails
        foreach ($metadata['sizes'] ?? [] as $size) {
            $thumb_path = path_join(dirname($file), $size['file']);
            if (file_exists($thumb_path)) {
                @unlink($thumb_path);
            }
        }

        // Delete the original file after conversion
        Logger::log("🧹 Deleting original file after WebP conversion: " . basename($file));
        @unlink($file);

        // Store WebP conversion savings and original format info
        update_post_meta($attachment_id, '_pic_pilot_webp_conversion_savings', $conversion_savings);
        update_post_meta($attachment_id, '_pic_pilot_original_format', $mime);
        update_post_meta($attachment_id, '_pic_pilot_original_size', $original_size);
        
        // Regenerate thumbnails from WebP
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $webp_path);
        
        // Clean up oversized thumbnails if resize was enabled
        if (Settings::is_enabled('resize_on_upload')) {
            $max_width = (int) Settings::get('resize_max_width', 2048);
            if ($max_width > 0 && isset($new_metadata['sizes'])) {
                foreach ($new_metadata['sizes'] as $size_name => $info) {
                    $thumb_path = path_join(dirname($webp_path), $info['file']);
                    if (file_exists($thumb_path)) {
                        [$width] = getimagesize($thumb_path);
                        if ($width > $max_width) {
                            @unlink($thumb_path);
                            Logger::log("🧹 Removed oversized thumbnail from WebP conversion [{$size_name}]: {$info['file']} ({$width}px)");
                            unset($new_metadata['sizes'][$size_name]);
                        }
                    }
                }
            }
        }
        
        wp_update_attachment_metadata($attachment_id, $new_metadata);

        // Replace references in content/meta
        if (class_exists('\PicPilot\Restore\ContentUpdater')) {
            \PicPilot\Restore\ContentUpdater::replace_image_urls($attachment_id, $old_url, $new_url);
        }

        clean_post_cache($attachment_id);

        Logger::log("✅ WebP conversion completed for ID $attachment_id");
        return $new_metadata;
    }

    // Convert opaque PNGs to JPEG if the setting is enabled
    public static function maybeConvertPngToJpeg(array $metadata, int $attachment_id): array {

        $file = get_attached_file($attachment_id);
        $mime = mime_content_type($file);

        // Skip if WebP conversion already happened
        if (Settings::is_enabled('convert_to_webp_on_upload') && WebPConverter::hasWebPSupport()) {
            Logger::log("⏩ Skipping PNG→JPEG conversion: WebP conversion takes priority");
            return $metadata;
        }

        if ($mime !== 'image/png') {
            return $metadata;
        }

        global $pic_pilot_restoring;
        if (!empty($pic_pilot_restoring)) {
            \PicPilot\Logger::log("🛑 Skipping maybeConvertPngToJpeg during restore for ID $attachment_id (MIME: $mime)");
            return $metadata;
        }

        // PNG→JPEG conversion is now handled in conversion mode via WebP
        // This legacy function is disabled in favor of the new conversion system
        if (!Settings::is_enabled('convert_png_to_jpeg_if_opaque') || Settings::get('upload_mode') === 'convert') {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!file_exists($file) || mime_content_type($file) !== 'image/png') {
            return $metadata;
        }

        if (!Utils::isOpaquePng($file)) {
            return $metadata;
        }

        // Record original PNG size for savings calculation
        $original_png_size = filesize($file);
        Logger::log("📊 Original PNG size: {$original_png_size} bytes");

        // Backup original PNG and metadata
        if (Settings::is_enabled('enable_backup')) {
            BackupService::create_backup($attachment_id);
        }

        // Convert PNG → JPEG
        $newPath = PngToJpegConverter::convert($file);
        if (!$newPath || !file_exists($newPath)) {
            return $metadata;
        }
        
        $jpeg_size = filesize($newPath);
        $conversion_savings = max(0, $original_png_size - $jpeg_size);
        Logger::log("🔄 PNG→JPEG conversion: {$original_png_size} → {$jpeg_size} bytes (Saved: {$conversion_savings})");

        // Handle resize on the newly converted JPEG if needed
        // Only resize if resize_on_upload is enabled AND keep_original_after_resize is disabled
        $should_resize = Settings::is_enabled('resize_on_upload') && !Settings::is_enabled('keep_original_after_resize');
        
        if ($should_resize) {
            $max_width = (int) Settings::get('resize_max_width', 2048);
            if ($max_width <= 0) {
                $max_width = 2048;
            }
            
            Logger::log("📦 PNG→JPEG resize check: max_width = {$max_width}");
            Logger::log("🔧 Resize decision: resize_on_upload=" . (Settings::is_enabled('resize_on_upload') ? 'true' : 'false') . ", keep_original_after_resize=" . (Settings::is_enabled('keep_original_after_resize') ? 'true' : 'false') . " → should_resize=" . ($should_resize ? 'true' : 'false'));
            
            $editor = wp_get_image_editor($newPath);
            if (!is_wp_error($editor)) {
                $size = $editor->get_size();
                Logger::log("🧪 PNG→JPEG resize check: width = {$size['width']}, max = {$max_width}");
                
                if ($size['width'] > $max_width) {
                    $pre_resize_size = filesize($newPath);
                    Logger::log("🔄 Resizing converted JPEG from {$size['width']}px to {$max_width}px");
                    
                    $resize_result = $editor->resize($max_width, null, false);
                    if (!is_wp_error($resize_result)) {
                        $save_result = $editor->save($newPath);
                        if (!is_wp_error($save_result)) {
                            $post_resize_size = filesize($newPath);
                            $resize_savings = $pre_resize_size - $post_resize_size;
                            Logger::log("✅ PNG→JPEG resize completed: {$pre_resize_size} → {$post_resize_size} bytes (Saved: {$resize_savings})");
                            
                            // Update conversion savings to include resize
                            $total_conversion_savings = $original_png_size - $post_resize_size;
                            Logger::log("📊 Total PNG→JPEG+resize savings: {$original_png_size} → {$post_resize_size} bytes (Saved: {$total_conversion_savings})");
                            $conversion_savings = $total_conversion_savings;
                            $jpeg_size = $post_resize_size;
                        }
                    }
                }
            }
        } else {
            Logger::log("📁 Skipping resize - keeping original size due to settings (resize_on_upload=" . (Settings::is_enabled('resize_on_upload') ? 'true' : 'false') . ", keep_original_after_resize=" . (Settings::is_enabled('keep_original_after_resize') ? 'true' : 'false') . ")");
        }

        $oldUrl = wp_get_attachment_url($attachment_id);
        $newUrl = str_replace(basename($file), basename($newPath), $oldUrl);

        // Update the attached file reference
        update_attached_file($attachment_id, $newPath);

        // Update post_mime_type in the attachment post
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => 'image/jpeg',
        ]);

        // Delete old thumbnails
        foreach ($metadata['sizes'] ?? [] as $size) {
            $thumbPath = path_join(dirname($file), $size['file']);
            if (file_exists($thumbPath)) {
                @unlink($thumbPath);
            }
        }
        // Always delete the original PNG after conversion - PNG→JPEG conversion should always remove the PNG
        // The keep_original_after_resize setting is for keeping large images, not for keeping old formats
        Logger::log("🧹 Deleting original PNG file after conversion (PNG→JPEG conversion always removes PNG): " . basename($file));
        @unlink($file);

        // Store PNG conversion savings for later use
        update_post_meta($attachment_id, '_pic_pilot_png_conversion_savings', $conversion_savings);
        update_post_meta($attachment_id, '_pic_pilot_original_png_size', $original_png_size);
        
        // Regenerate thumbnails from JPEG
        $newMetadata = wp_generate_attachment_metadata($attachment_id, $newPath);
        
        // Clean up oversized thumbnails if resize was enabled
        if (Settings::is_enabled('resize_on_upload')) {
            $max_width = (int) Settings::get('resize_max_width', 2048);
            if ($max_width > 0 && isset($newMetadata['sizes'])) {
                foreach ($newMetadata['sizes'] as $size_name => $info) {
                    $thumb_path = path_join(dirname($newPath), $info['file']);
                    if (file_exists($thumb_path)) {
                        [$width] = getimagesize($thumb_path);
                        if ($width > $max_width) {
                            @unlink($thumb_path);
                            Logger::log("🧹 Removed oversized thumbnail from PNG conversion [{$size_name}]: {$info['file']} ({$width}px)");
                            unset($newMetadata['sizes'][$size_name]);
                        }
                    }
                }
            }
        }
        
        wp_update_attachment_metadata($attachment_id, $newMetadata);

        // Replace references in content/meta
        if (class_exists('\PicPilot\ContentSync')) {
            \PicPilot\ContentSync::replace_attachment_references($oldUrl, $newUrl);
        }

        clean_post_cache($attachment_id);

        return $newMetadata;
    }


    /**
     * Optimize images on upload if the setting is enabled.
     *
     * @param array $metadata The attachment metadata.
     * @param int $attachment_id The attachment ID.
     * @return array The modified metadata.
    
     */



    public function maybe_optimize_on_upload(array $metadata, int $attachment_id): array {
        Logger::log('🔄 Checking if upload optimization is enabled for ID: ' . $attachment_id);
        
        // Check if already optimized to prevent double optimization
        $existing_optimization = get_post_meta($attachment_id, '_pic_pilot_optimization', true);
        if (!empty($existing_optimization) && $existing_optimization['status'] === 'optimized') {
            Logger::log('🚫 Already optimized during PNG→JPEG conversion, skipping duplicate optimization');
            return $metadata;
        }

        // Skip optimization for WebP files (already optimized format)
        $mime = get_post_mime_type($attachment_id);
        if ($mime === 'image/webp') {
            Logger::log('🚫 Skipping optimization for WebP file (already optimized format)');
            return $metadata;
        }
        
        // Exit early if optimize on upload setting is disabled
        $settings = Settings::all();
        Logger::log('🔧 Settings dump: ' . json_encode($settings));

        if (!Settings::is_enabled('optimize_on_upload')) {
            Logger::log('🚫 Compression on upload is disabled via settings.');
            return $metadata;
        }

        Logger::log('✅ Compression on upload is ENABLED. Proceeding...');

        // Only optimize valid image files
        $mime = get_post_mime_type($attachment_id);
        if (strpos($mime, 'image/') !== 0) {
            Logger::log('🚫 Not an image upload: ' . $mime);
            return $metadata;
        }

        // Handle resize before optimization
        $path = get_attached_file($attachment_id);
        if (Settings::is_enabled('resize_on_upload') && $path && file_exists($path)) {
            $metadata = $this->handle_resize_on_upload($metadata, $attachment_id, $path);
        }

        // Attempt to optimize the attachment
        $result = Optimizer::optimize_attachment($attachment_id);

        if (!$result['success']) {
            Logger::log('❌ Upload optimization failed: ' . ($result['error'] ?? 'unknown'));
        } else {
            Logger::log('✅ Upload optimization succeeded for ID: ' . $attachment_id);
        }

        return $metadata;
    }

    /**
     * Handle image resizing on upload
     */
    private function handle_resize_on_upload(array $metadata, int $attachment_id, string $path): array {
        $max_width = (int) Settings::get('resize_max_width', 2048);
        if ($max_width <= 0) {
            $max_width = 2048; // WordPress default
        }

        Logger::log("📦 Resize settings: max_width = {$max_width}");

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            Logger::log("❌ Could not get image editor for resize: " . $editor->get_error_message());
            return $metadata;
        }

        $size = $editor->get_size();
        Logger::log("🧪 Resize check: width = {$size['width']}, max = {$max_width}");

        if ($size['width'] > $max_width) {
            $original_size = filesize($path);
            
            Logger::log("🔄 Resizing image from {$size['width']}px to {$max_width}px");
            
            // Check if we should keep the original before modifying the file
            $keep_original = Settings::is_enabled('keep_original_after_resize');
            Logger::log("🔧 keep_original_after_resize setting: " . ($keep_original ? 'true' : 'false'));
            
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
                    Logger::log("❌ Resize failed: " . $resize_result->get_error_message());
                    return $metadata;
                }

                $save_result = $editor->save($resized_path);
                if (is_wp_error($save_result)) {
                    Logger::log("❌ Save resized version failed: " . $save_result->get_error_message());
                    return $metadata;
                }
                
                // Update the attachment to point to the resized version but keep original file
                update_attached_file($attachment_id, $resized_path);
                
                $new_size = filesize($resized_path);
                $savings = $original_size - $new_size;
                Logger::log("✅ Resize completed: {$original_size} → {$new_size} bytes (Saved: {$savings})");
                Logger::log("📁 Kept original pre-resize upload: " . basename($path));
                Logger::log("📁 Created resized version: " . basename($resized_path));
                
                // Update path for thumbnail cleanup
                $path = $resized_path;
            } else {
                // Resize in-place when not keeping original
                $resize_result = $editor->resize($max_width, null, false);
                if (is_wp_error($resize_result)) {
                    Logger::log("❌ Resize failed: " . $resize_result->get_error_message());
                    return $metadata;
                }

                $save_result = $editor->save($path);
                if (is_wp_error($save_result)) {
                    Logger::log("❌ Save after resize failed: " . $save_result->get_error_message());
                    return $metadata;
                }

                $new_size = filesize($path);
                $savings = $original_size - $new_size;
                Logger::log("✅ Resize completed: {$original_size} → {$new_size} bytes (Saved: {$savings})");
                Logger::log("🧹 Resized in-place, original file replaced: " . basename($path));
            }

            // Remove oversized thumbnails
            if (isset($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_name => $info) {
                    $thumb_path = path_join(dirname($path), $info['file']);
                    if (file_exists($thumb_path)) {
                        [$width] = getimagesize($thumb_path);
                        if ($width > $max_width) {
                            @unlink($thumb_path);
                            Logger::log("🧹 Removed oversized thumbnail [{$size_name}]: {$info['file']} ({$width}px)");
                            unset($metadata['sizes'][$size_name]);
                        }
                    }
                }
            }
        }

        return $metadata;
    }


    /**
     * Optimize an uploaded image after it's been added to the Media Library.
     *
     * @param array $metadata
     * @param int $attachmentId
     * @return array
     */
    public function handleUpload($metadata, $attachmentId) {


        if (!\PicPilot\Settings::is_enabled('optimize_on_upload')) {
            return $metadata;
        }

        //Check settings to see if resize is enabled
        $resize_enabled = \PicPilot\Settings::is_enabled('resize_on_upload');
        $max_width = $resize_enabled ? min(Settings::get('resize_max_width', 2048), 2048) : null;


        Logger::log("📦 resize_on_upload value = " . var_export(Settings::get('resize_on_upload'), true));
        Logger::log("📦 resize_on_upload (evaluated) = " . ($resize_enabled ? '✅ yes' : '❌ no'));
        Logger::log("📦 resize_max_width = " . $max_width);




        $path = get_attached_file($attachmentId);

        if (!file_exists($path) || !wp_attachment_is_image($attachmentId)) {
            return $metadata;
        }

        try {
            //check max widhth and resize if necessary
            if ($resize_enabled && $max_width > 0) {
                $original_path = $path; // BEFORE resize happens
                $editor = wp_get_image_editor($path);
                if (!is_wp_error($editor)) {
                    $size = $editor->get_size();
                    $this->logger->log("🧪 Resize check: width = {$size['width']}, max = $max_width");

                    if ($size['width'] > $max_width) {
                        $editor->resize($max_width, null, false);
                        $editor->save($path);

                        // ✅ Clean up original if different and user preference allows
                        if ($original_path !== get_attached_file($attachmentId)) {
                            $keep_original = Settings::is_enabled('keep_original_after_resize');
                            if (!$keep_original) {
                                @unlink($original_path);
                                \PicPilot\Logger::log("🧹 Deleted original pre-resize upload: " . basename($original_path));
                            } else {
                                \PicPilot\Logger::log("📁 Kept original pre-resize upload: " . basename($original_path));
                            }
                        }
                    }
                }
            }

            //After resizing, clean bigger images than the max width
            if ($resize_enabled && $max_width > 0) {
                foreach ($metadata['sizes'] as $size => $info) {
                    $thumb_path = path_join(dirname($path), $info['file']);

                    if (file_exists($thumb_path)) {
                        [$width] = getimagesize($thumb_path);

                        if ($width > $max_width) {
                            unlink($thumb_path); // remove too-large thumbnail
                            $this->logger->log("🧹 Removed oversized thumbnail [$size]: $info[file] ({$width} px)");
                            unset($metadata['sizes'][$size]); // remove from metadata
                        }
                    }
                }
            }


            // Create a backup before compression
            \PicPilot\Backup\BackupService::create_backup($attachmentId);

            //Delete oversized images
            if ($resize_enabled) {
                $metadata = Utils::clean_oversized_images($metadata, $path, $max_width);
            }


            $mime = mime_content_type($path);
            $compressor = $this->router::get_compressor($mime);
            $main_result = $compressor->compress($path);

            // Compress all thumbnails and sum up saved bytes
            $thumbs_saved = \PicPilot\Utils::compress_thumbnails($attachmentId, $compressor, $path);

            $total_saved = ($main_result['saved'] ?? 0) + $thumbs_saved;

            // Save meta/status as before
            if ($main_result && $total_saved > 0) {
                \PicPilot\Utils::update_compression_metadata(
                    $attachmentId,
                    (int) $total_saved,
                    sanitize_text_field($main_result['engine'] ?? 'unknown'),
                    false
                );
                // Log the successful compressionyes
                $this->logger->log("✅ Compressed on upload", [
                    'attachment_id' => $attachmentId,
                    'engine' => $main_result['engine'] ?? 'unknown',
                    'bytes_saved' => $total_saved,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->log("❌ Upload compression failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }


        return $metadata;
    }
}
